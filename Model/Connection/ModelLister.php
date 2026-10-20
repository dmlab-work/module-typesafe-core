<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypeSafeCore\Model\Connection;

use DmLab\TypeSafeCore\Exception\AuthenticationException;
use DmLab\TypeSafeCore\Exception\NotConfiguredException;
use DmLab\TypeSafeCore\Exception\RequestException;
use DmLab\TypeSafeCore\Exception\TransportException;
use DmLab\TypeSafeCore\Model\Config;
use DmLab\TypeSafeCore\Model\Http\HttpRequest;
use DmLab\TypeSafeCore\Model\Http\HttpResponse;
use DmLab\TypeSafeCore\Model\Http\TransportInterface;

/**
 * Lists the models available to the saved API key; works while the module is disabled.
 */
class ModelLister
{
    public const ENDPOINT = '/v1/models';
    private const MAX_TIMEOUT = 10;
    private const ERROR_SNIPPET_LENGTH = 200;

    /**
     * @param Config $config
     * @param TransportInterface $transport
     */
    public function __construct(
        private readonly Config $config,
        private readonly TransportInterface $transport
    ) {
    }

    /**
     * Model names from `GET /v1/models`, single attempt.
     *
     * @return string[]
     * @throws NotConfiguredException
     * @throws AuthenticationException
     * @throws RequestException
     * @throws TransportException
     */
    public function listModels(): array
    {
        $apiKey = $this->config->getApiKey();
        if ($apiKey === '') {
            throw new NotConfiguredException(__('TypeSafe API key is not set.'));
        }

        $request = new HttpRequest(
            'GET',
            $this->config->getBaseUrl() . self::ENDPOINT,
            ['Authorization' => 'Bearer ' . $apiKey, 'Accept' => 'application/json'],
            null,
            min($this->config->getTimeout(), self::MAX_TIMEOUT)
        );
        $response = $this->transport->sendMany([0 => $request])[0];

        $this->assertSuccess($response);

        return $this->parse($response->getBody());
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
        $status = $response->getStatus();
        if ($response->isTransportError()) {
            throw new TransportException(
                __('TypeSafe request failed: %1', $response->getError() !== '' ? $response->getError() : 'no response')
            );
        }
        if ($status === 401 || $status === 403) {
            throw new AuthenticationException(__('TypeSafe rejected the API key (HTTP %1).', $status), null, $status);
        }
        if ($status < 200 || $status >= 300) {
            throw new RequestException(
                __(
                    'TypeSafe rejected the request (HTTP %1): %2',
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
    private function parse(string $body): array
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
