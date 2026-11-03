<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypeSafeCore\Model\Provider\TypeSafe;

use DmLab\TypeSafeCore\Api\Data\Request;
use DmLab\TypeSafeCore\Api\Data\Response;
use DmLab\TypeSafeCore\Api\ProviderInterface;
use DmLab\TypeSafeCore\Exception\AuthenticationException;
use DmLab\TypeSafeCore\Exception\NotConfiguredException;
use DmLab\TypeSafeCore\Exception\RequestException;
use DmLab\TypeSafeCore\Exception\TransportException;
use DmLab\TypeSafeCore\Exception\TypeSafeException;
use DmLab\TypeSafeCore\Model\Http\Backoff;
use DmLab\TypeSafeCore\Model\Http\HttpRequest;
use DmLab\TypeSafeCore\Model\Http\HttpResponse;
use DmLab\TypeSafeCore\Model\Http\TransportInterface;
use DmLab\TypeSafeCore\Model\Json\CanonicalJson;
use DmLab\TypeSafeCore\Model\Provider\ProviderSettings;

/**
 * TypeSafe System One (`POST /v1/systemone`): questions go out as-is, typed answers come back.
 */
class TypeSafeProvider implements ProviderInterface
{
    public const CODE = 'typesafe';
    public const ENDPOINT = '/v1/systemone';
    public const MODELS_ENDPOINT = '/v1/models';
    public const MAX_BODY_BYTES = 32 * 1024 * 1024;
    private const MODELS_MAX_TIMEOUT = 10;
    private const ERROR_SNIPPET_LENGTH = 200;

    /**
     * @param ProviderSettings $settings
     * @param ResponseParser $parser
     * @param Backoff $backoff
     * @param TransportInterface $transport
     */
    public function __construct(
        private readonly ProviderSettings $settings,
        private readonly ResponseParser $parser,
        private readonly Backoff $backoff,
        private readonly TransportInterface $transport
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getCode(): string
    {
        return self::CODE;
    }

    /**
     * @inheritDoc
     */
    public function getLabel(): string
    {
        return 'TypeSafe';
    }

    /**
     * @inheritDoc
     */
    public function isConfigured(): bool
    {
        return $this->settings->getApiKey(self::CODE) !== '';
    }

    /**
     * @inheritDoc
     */
    public function getModel(): string
    {
        return $this->settings->getModel(self::CODE);
    }

    /**
     * Empty, so cache keys and rows written before providers existed stay valid.
     *
     * @return string
     */
    public function cacheScope(): string
    {
        return '';
    }

    /**
     * @inheritDoc
     */
    public function buildRequest(Request $request, string $model, ?string $repairNote): HttpRequest
    {
        try {
            $body = json_encode([
                'model' => $model,
                'state' => $request->getState(),
                'questions' => (object)array_map(
                    static fn ($question) => $question->toArray(),
                    $request->getQuestions()
                ),
            ], CanonicalJson::FLAGS);
        } catch (\JsonException $e) {
            throw new RequestException(
                __('%1 request could not be encoded: %2', $this->getLabel(), $e->getMessage()),
                $e
            );
        }
        if (strlen($body) > self::MAX_BODY_BYTES) {
            throw new RequestException(__('TypeSafe request body exceeds 32 MiB.'));
        }

        return new HttpRequest(
            'POST',
            $this->settings->getBaseUrl(self::CODE) . self::ENDPOINT,
            [
                'Authorization' => 'Bearer ' . $this->settings->getApiKey(self::CODE),
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'Expect' => '',
            ],
            $body,
            $this->settings->getTimeout()
        );
    }

    /**
     * @inheritDoc
     */
    public function parseResponse(string $body, Request $request, string $model, bool $cached): Response
    {
        return $this->parser->parse($body, $request, $model, $cached);
    }

    /**
     * @inheritDoc
     */
    public function usage(string $body): array
    {
        return ['input' => $this->parser->billedTokens($body), 'output' => 0];
    }

    /**
     * @inheritDoc
     */
    public function classifyError(HttpResponse $response): ?TypeSafeException
    {
        return null;
    }

    /**
     * @inheritDoc
     */
    public function retryAfter(HttpResponse $response): ?int
    {
        return $this->backoff->parseRetryAfter($response->getHeader('retry-after'));
    }

    /**
     * Model names from `GET /v1/models`; works while the module is disabled.
     *
     * @return string[]
     * @throws NotConfiguredException
     * @throws AuthenticationException
     * @throws RequestException
     * @throws TransportException
     */
    public function listModels(): array
    {
        $apiKey = $this->settings->getApiKey(self::CODE);
        if ($apiKey === '') {
            throw new NotConfiguredException(__('%1 API key is not set.', $this->getLabel()));
        }

        $request = new HttpRequest(
            'GET',
            $this->settings->getBaseUrl(self::CODE) . self::MODELS_ENDPOINT,
            ['Authorization' => 'Bearer ' . $apiKey, 'Accept' => 'application/json'],
            null,
            min($this->settings->getTimeout(), self::MODELS_MAX_TIMEOUT)
        );
        $response = $this->transport->sendMany([0 => $request])[0];
        $this->assertSuccess($response);

        return $this->parseModels($response->getBody());
    }

    /**
     * Throw the mapped exception for a transport error or a non-2xx status.
     *
     * @param HttpResponse $response
     * @return void
     * @throws AuthenticationException
     * @throws RequestException
     * @throws TransportException
     */
    private function assertSuccess(HttpResponse $response): void
    {
        $label = $this->getLabel();
        $status = $response->getStatus();
        if ($response->isTransportError()) {
            throw new TransportException(__(
                '%1 request failed: %2',
                $label,
                $response->getError() !== '' ? $response->getError() : 'no response'
            ));
        }
        if ($status === 401 || $status === 403) {
            throw new AuthenticationException(
                __('%1 rejected the API key (HTTP %2).', $label, $status),
                null,
                $status
            );
        }
        if ($status < 200 || $status >= 300) {
            throw new RequestException(
                __(
                    '%1 rejected the request (HTTP %2): %3',
                    $label,
                    $status,
                    mb_substr(trim($response->getBody()), 0, self::ERROR_SNIPPET_LENGTH)
                ),
                null,
                $status
            );
        }
    }

    /**
     * Names from `{"models":[{"name":…}, …]}`.
     *
     * @param string $body
     * @return string[]
     * @throws TransportException
     */
    private function parseModels(string $body): array
    {
        try {
            $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new TransportException(__('TypeSafe returned a malformed response: %1', $e->getMessage()), $e);
        }
        if (!is_array($data) || !is_array($data['models'] ?? null)) {
            throw new TransportException(__('TypeSafe returned a malformed response: %1', 'no models list'));
        }

        $names = [];
        foreach ($data['models'] as $model) {
            $name = is_array($model) ? ($model['name'] ?? null) : null;
            if (is_string($name) && trim($name) !== '') {
                $names[] = trim($name);
            }
        }

        return $names;
    }
}
