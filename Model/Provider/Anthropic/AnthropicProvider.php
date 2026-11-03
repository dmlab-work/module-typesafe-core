<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypeSafeCore\Model\Provider\Anthropic;

use DmLab\TypeSafeCore\Api\Data\Request;
use DmLab\TypeSafeCore\Api\Data\Response;
use DmLab\TypeSafeCore\Exception\InvalidAnswerException;
use DmLab\TypeSafeCore\Exception\TypeSafeException;
use DmLab\TypeSafeCore\Model\Http\HttpRequest;
use DmLab\TypeSafeCore\Model\Llm\QuestionKeys;
use DmLab\TypeSafeCore\Model\Llm\SchemaBuilder;
use DmLab\TypeSafeCore\Model\Provider\AbstractLlmProvider;

/**
 * Anthropic Messages API with JSON-schema structured output (`output_config.format`).
 *
 * No `temperature`: API version 2026-10-21 rejects it on every model.
 */
class AnthropicProvider extends AbstractLlmProvider
{
    public const CODE = 'anthropic';
    public const API_VERSION = '2026-10-21';
    public const ENDPOINT = '/v1/messages';
    /** Largest page the models endpoint allows, so the configured model is found without paging. */
    public const MODELS_ENDPOINT = '/v1/models?limit=1000';

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
        return 'Anthropic API';
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
            'model' => $model,
            'max_tokens' => self::MAX_OUTPUT_TOKENS,
            'system' => $prompt['system'],
            'messages' => [['role' => 'user', 'content' => $prompt['user']]],
            'output_config' => [
                'effort' => 'low',
                'format' => [
                    'type' => 'json_schema',
                    'schema' => $this->schemaBuilder->build($keys, SchemaBuilder::DIALECT_ANTHROPIC),
                ],
            ],
        ];

        return $this->post($this->settings->getBaseUrl(self::CODE) . self::ENDPOINT, $this->authHeaders(), $payload);
    }

    /**
     * @inheritDoc
     */
    public function parseResponse(string $body, Request $request, string $model, bool $cached): Response
    {
        $data = $this->decodeBody($body);
        $content = $data['content'] ?? null;
        if (!is_array($content)) {
            throw $this->malformed('no content');
        }
        $stop = $data['stop_reason'] ?? null;
        if ($stop === 'refusal') {
            throw new InvalidAnswerException(__('Invalid answer: %1', 'the model refused to answer.'));
        }
        if ($stop !== 'end_turn') {
            throw new InvalidAnswerException(__(
                'Invalid answer: %1',
                sprintf('the reply ended with stop_reason "%s".', is_string($stop) ? $stop : 'none')
            ));
        }
        $text = '';
        foreach ($content as $block) {
            if (is_array($block) && ($block['type'] ?? null) === 'text' && is_string($block['text'] ?? null)) {
                $text .= $block['text'];
            }
        }
        if (trim($text) === '') {
            throw new InvalidAnswerException(__('Invalid answer: %1', 'the reply is empty.'));
        }
        $replyModel = $data['model'] ?? null;

        return $this->response(
            $text,
            $body,
            $request,
            is_string($replyModel) && $replyModel !== '' ? $replyModel : $model,
            $cached
        );
    }

    /**
     * Model ids from `GET {base_url}/v1/models`.
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
     * Prompt-cache writes and reads are billed input too.
     *
     * @param array $data
     * @return array{input: mixed, output: mixed}
     */
    protected function readUsage(array $data): array
    {
        $usage = is_array($data['usage'] ?? null) ? $data['usage'] : [];
        $input = 0;
        foreach (['input_tokens', 'cache_creation_input_tokens', 'cache_read_input_tokens'] as $field) {
            $input += $this->tokens($usage[$field] ?? 0);
        }

        return ['input' => $input, 'output' => $usage['output_tokens'] ?? 0];
    }

    /**
     * Key and API version headers.
     *
     * @return array<string, string>
     */
    private function authHeaders(): array
    {
        return ['x-api-key' => $this->settings->getApiKey(self::CODE), 'anthropic-version' => self::API_VERSION];
    }
}
