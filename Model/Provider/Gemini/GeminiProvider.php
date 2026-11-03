<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypeSafeCore\Model\Provider\Gemini;

use DmLab\TypeSafeCore\Api\Data\Request;
use DmLab\TypeSafeCore\Api\Data\Response;
use DmLab\TypeSafeCore\Exception\AuthenticationException;
use DmLab\TypeSafeCore\Exception\InvalidAnswerException;
use DmLab\TypeSafeCore\Exception\TypeSafeException;
use DmLab\TypeSafeCore\Model\Http\HttpRequest;
use DmLab\TypeSafeCore\Model\Http\HttpResponse;
use DmLab\TypeSafeCore\Model\Llm\QuestionKeys;
use DmLab\TypeSafeCore\Model\Llm\SchemaBuilder;
use DmLab\TypeSafeCore\Model\Provider\AbstractLlmProvider;

/**
 * Gemini API `generateContent` with `responseSchema` structured output.
 *
 * The key goes in the `x-goog-api-key` header, never in the `?key=` query string, so it stays out of URLs and logs.
 */
class GeminiProvider extends AbstractLlmProvider
{
    public const CODE = 'gemini';
    public const API_PATH = '/v1beta/models/';
    /** Largest page the models endpoint allows, so the configured model is found without paging. */
    public const MODELS_ENDPOINT = '/v1beta/models?pageSize=1000';
    private const MODEL_PREFIX = 'models/';

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
        return 'Gemini API';
    }

    /**
     * API key set.
     *
     * @return bool
     */
    public function isConfigured(): bool
    {
        return $this->settings->getApiKey(self::CODE) !== '';
    }

    /**
     * @inheritDoc
     */
    public function buildRequest(Request $request, string $model, ?string $repairNote): HttpRequest
    {
        $keys = new QuestionKeys($request);
        $prompt = $this->prompt($request, $keys, $repairNote);
        $payload = [
            'systemInstruction' => ['parts' => [['text' => $prompt['system']]]],
            'contents' => [['role' => 'user', 'parts' => [['text' => $prompt['user']]]]],
            'generationConfig' => [
                'temperature' => 0,
                'responseMimeType' => 'application/json',
                'responseSchema' => $this->schemaBuilder->build($keys, SchemaBuilder::DIALECT_GEMINI),
                'maxOutputTokens' => self::MAX_OUTPUT_TOKENS,
            ],
        ];

        return $this->post(
            $this->settings->getBaseUrl(self::CODE) . self::API_PATH . rawurlencode($this->stripPrefix($model))
                . ':generateContent',
            $this->authHeaders(),
            $payload
        );
    }

    /**
     * @inheritDoc
     */
    public function parseResponse(string $body, Request $request, string $model, bool $cached): Response
    {
        $data = $this->decodeBody($body);
        $candidate = is_array($data['candidates'] ?? null) ? ($data['candidates'][0] ?? null) : null;
        if (!is_array($candidate)) {
            $blocked = is_array($data['promptFeedback'] ?? null) ? ($data['promptFeedback']['blockReason'] ?? null)
                : null;
            if (is_string($blocked) && $blocked !== '') {
                throw new InvalidAnswerException(__(
                    'Invalid answer: %1',
                    sprintf('the prompt was blocked with blockReason "%s".', $blocked)
                ));
            }
            throw $this->malformed('no candidates');
        }
        $finish = $candidate['finishReason'] ?? null;
        if ($finish !== 'STOP') {
            throw new InvalidAnswerException(__(
                'Invalid answer: %1',
                sprintf('the reply ended with finishReason "%s".', is_string($finish) ? $finish : 'none')
            ));
        }
        $parts = is_array($candidate['content'] ?? null) ? ($candidate['content']['parts'] ?? []) : [];
        $text = '';
        foreach (is_array($parts) ? $parts : [] as $part) {
            if (is_array($part) && ($part['thought'] ?? false) !== true && is_string($part['text'] ?? null)) {
                $text .= $part['text'];
            }
        }
        if (trim($text) === '') {
            throw new InvalidAnswerException(__('Invalid answer: %1', 'the reply is empty.'));
        }
        $replyModel = $data['modelVersion'] ?? null;

        return $this->response(
            $text,
            $body,
            $request,
            is_string($replyModel) && $replyModel !== '' ? $replyModel : $model,
            $cached
        );
    }

    /**
     * 400 `API_KEY_INVALID` is an authentication error; 401/403 use the default mapping.
     *
     * @param HttpResponse $response
     * @return TypeSafeException|null
     */
    public function classifyError(HttpResponse $response): ?TypeSafeException
    {
        $status = $response->getStatus();
        if ($status !== 400 || !in_array('API_KEY_INVALID', $this->errorReasons($response), true)) {
            return null;
        }

        return new AuthenticationException(
            __('%1 rejected the API key (HTTP %2).', $this->getLabel(), $status),
            null,
            $status
        );
    }

    /**
     * `retry-after` header, else the `google.rpc.RetryInfo` `retryDelay` of the error body (`"17s"`), rounded up.
     *
     * @param HttpResponse $response
     * @return int|null
     */
    public function retryAfter(HttpResponse $response): ?int
    {
        $header = parent::retryAfter($response);
        if ($header !== null) {
            return $header;
        }
        foreach ($this->errorDetails($response) as $detail) {
            $delay = $detail['retryDelay'] ?? null;
            if (is_string($delay) && preg_match('/^(\d+(?:\.\d+)?)s$/', trim($delay), $matches)) {
                return (int)ceil((float)$matches[1]);
            }
        }

        return null;
    }

    /**
     * Model ids from `GET {base_url}/v1beta/models`, without the `models/` prefix.
     *
     * @return string[]
     * @throws TypeSafeException
     */
    public function listModels(): array
    {
        $data = $this->getJson($this->settings->getBaseUrl(self::CODE) . self::MODELS_ENDPOINT, $this->authHeaders());

        return array_values(array_map(
            fn (string $name): string => $this->stripPrefix($name),
            $this->listField($data['models'] ?? null, 'name')
        ));
    }

    /**
     * Thinking tokens are billed output too.
     *
     * @param array $data
     * @return array{input: mixed, output: mixed}
     */
    protected function readUsage(array $data): array
    {
        $usage = is_array($data['usageMetadata'] ?? null) ? $data['usageMetadata'] : [];
        $output = 0;
        foreach (['candidatesTokenCount', 'thoughtsTokenCount'] as $field) {
            $output += $this->tokens($usage[$field] ?? 0);
        }

        return ['input' => $usage['promptTokenCount'] ?? 0, 'output' => $output];
    }

    /**
     * Key header.
     *
     * @return array<string, string>
     */
    private function authHeaders(): array
    {
        return ['x-goog-api-key' => $this->settings->getApiKey(self::CODE)];
    }

    /**
     * Model id without the `models/` resource prefix.
     *
     * @param string $name
     * @return string
     */
    private function stripPrefix(string $name): string
    {
        return str_starts_with($name, self::MODEL_PREFIX) ? substr($name, strlen(self::MODEL_PREFIX)) : $name;
    }

    /**
     * `error.details[]` entries of an error body.
     *
     * @param HttpResponse $response
     * @return array[]
     */
    private function errorDetails(HttpResponse $response): array
    {
        $data = json_decode($response->getBody(), true);
        $details = is_array($data) && is_array($data['error'] ?? null) ? ($data['error']['details'] ?? null) : null;

        return is_array($details) ? array_values(array_filter($details, 'is_array')) : [];
    }

    /**
     * `error.details[].reason` values of an error body.
     *
     * @param HttpResponse $response
     * @return string[]
     */
    private function errorReasons(HttpResponse $response): array
    {
        $reasons = [];
        foreach ($this->errorDetails($response) as $detail) {
            if (is_string($detail['reason'] ?? null)) {
                $reasons[] = $detail['reason'];
            }
        }

        return $reasons;
    }
}
