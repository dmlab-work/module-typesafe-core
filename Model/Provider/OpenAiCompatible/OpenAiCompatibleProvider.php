<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypeSafeCore\Model\Provider\OpenAiCompatible;

use DmLab\TypeSafeCore\Api\Data\Request;
use DmLab\TypeSafeCore\Api\Data\Response;
use DmLab\TypeSafeCore\Exception\InvalidAnswerException;
use DmLab\TypeSafeCore\Exception\RequestException;
use DmLab\TypeSafeCore\Exception\TypeSafeException;
use DmLab\TypeSafeCore\Model\Http\HttpRequest;
use DmLab\TypeSafeCore\Model\Http\HttpResponse;
use DmLab\TypeSafeCore\Model\Llm\QuestionKeys;
use DmLab\TypeSafeCore\Model\Llm\SchemaBuilder;
use DmLab\TypeSafeCore\Model\Provider\AbstractLlmProvider;

/**
 * Chat Completions with strict Structured Outputs: OpenAI, Azure OpenAI, OpenRouter, Groq, Ollama, vLLM, …
 */
class OpenAiCompatibleProvider extends AbstractLlmProvider
{
    public const CODE = 'openai_compatible';
    public const ENDPOINT = '/chat/completions';
    public const MODELS_ENDPOINT = '/models';
    private const AZURE_HOST_SUFFIXES = ['.openai.azure.com', '.services.ai.azure.com'];
    private const HOST_PATTERN = '#^[a-z][a-z0-9+.-]*://(?:[^@/?\#]*@)?([^:/?\#]+)#i';

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
        return 'OpenAI-compatible API';
    }

    /**
     * API key set, or a non-default (self-hosted) base URL, which may run keyless.
     *
     * @return bool
     */
    public function isConfigured(): bool
    {
        return $this->settings->getApiKey(self::CODE) !== ''
            || $this->settings->getBaseUrl(self::CODE) !== $this->settings->getDefaultBaseUrl(self::CODE);
    }

    /**
     * @inheritDoc
     */
    public function buildRequest(Request $request, string $model, ?string $repairNote): HttpRequest
    {
        $keys = new QuestionKeys($request);
        $prompt = $this->prompt($request, $keys, $repairNote);
        $payload = [
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => $prompt['system']],
                ['role' => 'user', 'content' => $prompt['user']],
            ],
            'response_format' => [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => 'answers',
                    'strict' => true,
                    'schema' => $this->schemaBuilder->build($keys, SchemaBuilder::DIALECT_OPENAI),
                ],
            ],
            'max_completion_tokens' => self::MAX_OUTPUT_TOKENS,
        ];
        if ($this->settings->isSendTemperature()) {
            $payload['temperature'] = 0;
        }

        return $this->post($this->settings->getBaseUrl(self::CODE) . self::ENDPOINT, $this->authHeaders(), $payload);
    }

    /**
     * @inheritDoc
     */
    public function parseResponse(string $body, Request $request, string $model, bool $cached): Response
    {
        $data = $this->decodeBody($body);
        $choice = $data['choices'][0] ?? null;
        if (!is_array($choice) || !is_array($choice['message'] ?? null)) {
            throw $this->malformed('no choices');
        }
        $refusal = $choice['message']['refusal'] ?? null;
        if ($refusal !== null) {
            throw new InvalidAnswerException(__('Invalid answer: %1', 'the model refused to answer.'));
        }
        $finish = $choice['finish_reason'] ?? null;
        if ($finish !== 'stop') {
            throw new InvalidAnswerException(__(
                'Invalid answer: %1',
                sprintf('the reply ended with finish_reason "%s".', is_string($finish) ? $finish : 'none')
            ));
        }
        $content = $choice['message']['content'] ?? null;
        if (!is_string($content) || trim($content) === '') {
            throw new InvalidAnswerException(__('Invalid answer: %1', 'the reply is empty.'));
        }
        $replyModel = $data['model'] ?? null;

        return $this->response(
            $content,
            $body,
            $request,
            is_string($replyModel) && $replyModel !== '' ? $replyModel : $model,
            $cached
        );
    }

    /**
     * `429 insufficient_quota` is a billing state, not a rate limit: final, never retried.
     *
     * @param HttpResponse $response
     * @return TypeSafeException|null
     */
    public function classifyError(HttpResponse $response): ?TypeSafeException
    {
        $status = $response->getStatus();
        if ($status !== 429) {
            return null;
        }
        $data = json_decode($response->getBody(), true);
        $code = is_array($data) && is_array($data['error'] ?? null) ? ($data['error']['code'] ?? null) : null;

        return $code === 'insufficient_quota'
            ? new RequestException(
                __(
                    '%1 quota is used up; check the plan and billing of the account (HTTP %2).',
                    $this->getLabel(),
                    $status
                ),
                null,
                $status
            )
            : null;
    }

    /**
     * Model ids from `GET {base_url}/models`.
     *
     * @return string[]
     * @throws TypeSafeException
     */
    public function listModels(): array
    {
        $data = $this->getJson($this->settings->getBaseUrl(self::CODE) . self::MODELS_ENDPOINT, $this->authHeaders());

        return $this->listField($data['data'] ?? null, 'id');
    }

    /**
     * @inheritDoc
     */
    protected function readUsage(array $data): array
    {
        return ['input' => $data['usage']['prompt_tokens'] ?? 0, 'output' => $data['usage']['completion_tokens'] ?? 0];
    }

    /**
     * Bearer key when set, plus `api-key` on Azure hosts.
     *
     * @return array<string, string>
     */
    private function authHeaders(): array
    {
        $apiKey = $this->settings->getApiKey(self::CODE);
        if ($apiKey === '') {
            return [];
        }
        $headers = ['Authorization' => 'Bearer ' . $apiKey];
        preg_match(self::HOST_PATTERN, $this->settings->getBaseUrl(self::CODE), $match);
        $host = strtolower($match[1] ?? '');
        foreach (self::AZURE_HOST_SUFFIXES as $suffix) {
            if (str_ends_with($host, $suffix)) {
                $headers['api-key'] = $apiKey;
            }
        }

        return $headers;
    }
}
