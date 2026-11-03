<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypeSafeCore\Model\Provider;

use DmLab\TypeSafeCore\Api\Data\Request;
use DmLab\TypeSafeCore\Api\Data\Response;
use DmLab\TypeSafeCore\Api\ProviderInterface;
use DmLab\TypeSafeCore\Exception\AuthenticationException;
use DmLab\TypeSafeCore\Exception\InvalidAnswerException;
use DmLab\TypeSafeCore\Exception\NotConfiguredException;
use DmLab\TypeSafeCore\Exception\RequestException;
use DmLab\TypeSafeCore\Exception\TransportException;
use DmLab\TypeSafeCore\Exception\TypeSafeException;
use DmLab\TypeSafeCore\Model\Http\Backoff;
use DmLab\TypeSafeCore\Model\Http\HttpRequest;
use DmLab\TypeSafeCore\Model\Http\HttpResponse;
use DmLab\TypeSafeCore\Model\Http\TransportInterface;
use DmLab\TypeSafeCore\Model\Json\CanonicalJson;
use DmLab\TypeSafeCore\Model\Llm\AnswerMapper;
use DmLab\TypeSafeCore\Model\Llm\PromptBuilder;
use DmLab\TypeSafeCore\Model\Llm\QuestionKeys;
use DmLab\TypeSafeCore\Model\Llm\SchemaBuilder;

/**
 * Base of chat-style LLM providers: shared prompt, schema, answer mapping, cache scope and usage plumbing.
 */
abstract class AbstractLlmProvider implements ProviderInterface
{
    /** Output token cap of one request. */
    public const MAX_OUTPUT_TOKENS = 16000;
    private const MODELS_MAX_TIMEOUT = 10;
    private const ERROR_SNIPPET_LENGTH = 200;

    /**
     * @param ProviderSettings $settings
     * @param PromptBuilder $promptBuilder
     * @param SchemaBuilder $schemaBuilder
     * @param AnswerMapper $answerMapper
     * @param Backoff $backoff
     * @param TransportInterface $transport
     */
    public function __construct(
        protected readonly ProviderSettings $settings,
        protected readonly PromptBuilder $promptBuilder,
        protected readonly SchemaBuilder $schemaBuilder,
        protected readonly AnswerMapper $answerMapper,
        protected readonly Backoff $backoff,
        protected readonly TransportInterface $transport
    ) {
    }

    /**
     * Billed tokens read from a decoded response body.
     *
     * @param array $data
     * @return array{input: mixed, output: mixed}
     */
    abstract protected function readUsage(array $data): array;

    /**
     * @inheritDoc
     */
    public function getModel(): string
    {
        return $this->settings->getModel($this->getCode());
    }

    /**
     * `code|base_url|PROMPT_VERSION`: another provider, endpoint or prompt never serves these answers.
     *
     * @return string
     */
    public function cacheScope(): string
    {
        return $this->getCode() . '|' . $this->settings->getBaseUrl($this->getCode()) . '|'
            . PromptBuilder::PROMPT_VERSION;
    }

    /**
     * @inheritDoc
     */
    public function usage(string $body): array
    {
        $data = json_decode($body, true);
        $usage = is_array($data) ? $this->readUsage($data) : [];

        return ['input' => $this->tokens($usage['input'] ?? 0), 'output' => $this->tokens($usage['output'] ?? 0)];
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
     * System prompt and user message of a request.
     *
     * @param Request $request
     * @param QuestionKeys $keys
     * @param string|null $repairNote
     * @return array{system: string, user: string}
     * @throws RequestException
     */
    protected function prompt(Request $request, QuestionKeys $keys, ?string $repairNote): array
    {
        try {
            $user = $this->promptBuilder->user($request, $keys, $repairNote);
        } catch (\JsonException $e) {
            throw new RequestException(
                __('%1 request could not be encoded: %2', $this->getLabel(), $e->getMessage()),
                $e
            );
        }

        return ['system' => $this->promptBuilder->system($keys), 'user' => $user];
    }

    /**
     * JSON POST with the provider's auth headers and the configured timeout.
     *
     * @param string $url
     * @param array $headers name => value
     * @param array $payload
     * @return HttpRequest
     * @throws RequestException
     */
    protected function post(string $url, array $headers, array $payload): HttpRequest
    {
        try {
            $body = json_encode($payload, CanonicalJson::FLAGS);
        } catch (\JsonException $e) {
            throw new RequestException(
                __('%1 request could not be encoded: %2', $this->getLabel(), $e->getMessage()),
                $e
            );
        }

        return new HttpRequest(
            'POST',
            $url,
            $headers + ['Content-Type' => 'application/json', 'Accept' => 'application/json', 'Expect' => ''],
            $body,
            $this->settings->getTimeout()
        );
    }

    /**
     * Decoded JSON object of a provider response body.
     *
     * @param string $body
     * @return array
     * @throws TransportException
     */
    protected function decodeBody(string $body): array
    {
        try {
            $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw $this->malformed('invalid JSON (' . $e->getMessage() . ')', $e);
        }
        if (!is_array($data)) {
            throw $this->malformed('not a JSON object');
        }

        return $data;
    }

    /**
     * Response from the model's text output; a cached response costs 0 tokens.
     *
     * @param string $text model output, JSON optionally inside a ``` fence
     * @param string $body raw provider body, for usage
     * @param Request $request
     * @param string $model
     * @param bool $cached
     * @return Response
     * @throws InvalidAnswerException
     */
    protected function response(string $text, string $body, Request $request, string $model, bool $cached): Response
    {
        $text = trim($text);
        if (preg_match('/^```(?:json)?\s*(.*?)\s*```$/s', $text, $matches)) {
            $text = $matches[1];
        }
        try {
            $decoded = json_decode($text, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new InvalidAnswerException(__('Invalid answer: %1', 'the reply is not valid JSON.'), $e);
        }

        $answers = $this->answerMapper->map($decoded, new QuestionKeys($request));

        return new Response($model, $cached ? 0 : $this->usage($body)['input'], $cached, $answers);
    }

    /**
     * Decoded JSON of a single-attempt GET, e.g. the models list; works while the module is disabled.
     *
     * @param string $url
     * @param array $headers name => value
     * @return array
     * @throws NotConfiguredException
     * @throws AuthenticationException
     * @throws RequestException
     * @throws TransportException
     */
    protected function getJson(string $url, array $headers): array
    {
        if (!$this->isConfigured()) {
            throw new NotConfiguredException(__('%1 API key is not set.', $this->getLabel()));
        }
        $request = new HttpRequest(
            'GET',
            $url,
            $headers + ['Accept' => 'application/json'],
            null,
            min($this->settings->getTimeout(), self::MODELS_MAX_TIMEOUT)
        );
        $response = $this->transport->sendMany([0 => $request])[0];

        $label = $this->getLabel();
        $status = $response->getStatus();
        if ($response->isTransportError()) {
            throw new TransportException(__(
                '%1 request failed: %2',
                $label,
                $response->getError() !== '' ? $response->getError() : 'no response'
            ));
        }
        $classified = $this->classifyError($response);
        if ($classified !== null) {
            throw $classified;
        }
        if ($status === 401 || $status === 403) {
            throw new AuthenticationException(__('%1 rejected the API key (HTTP %2).', $label, $status), null, $status);
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

        return $this->decodeBody($response->getBody());
    }

    /**
     * Non-empty string `$field` of every entry of a list, e.g. `data[].id`.
     *
     * @param mixed $list
     * @param string $field
     * @return string[]
     * @throws TransportException
     */
    protected function listField(mixed $list, string $field): array
    {
        if (!is_array($list)) {
            throw $this->malformed('no models list');
        }

        $values = [];
        foreach ($list as $entry) {
            $value = is_array($entry) ? ($entry[$field] ?? null) : null;
            if (is_string($value) && trim($value) !== '') {
                $values[] = trim($value);
            }
        }

        return $values;
    }

    /**
     * Malformed-body exception.
     *
     * @param string $detail
     * @param \Exception|null $cause
     * @return TransportException
     */
    protected function malformed(string $detail, ?\Exception $cause = null): TransportException
    {
        return new TransportException(__('%1 returned a malformed response: %2', $this->getLabel(), $detail), $cause);
    }

    /**
     * Non-negative token count; 0 for anything non-numeric.
     *
     * @param mixed $value
     * @return int
     */
    protected function tokens(mixed $value): int
    {
        return is_numeric($value) ? max(0, (int)$value) : 0;
    }
}
