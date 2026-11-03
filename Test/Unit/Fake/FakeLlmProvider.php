<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypeSafeCore\Test\Unit\Fake;

use DmLab\TypeSafeCore\Api\Data\Request;
use DmLab\TypeSafeCore\Api\Data\Response;
use DmLab\TypeSafeCore\Model\Http\Backoff;
use DmLab\TypeSafeCore\Model\Http\HttpRequest;
use DmLab\TypeSafeCore\Model\Llm\AnswerMapper;
use DmLab\TypeSafeCore\Model\Llm\PromptBuilder;
use DmLab\TypeSafeCore\Model\Llm\QuestionKeys;
use DmLab\TypeSafeCore\Model\Llm\SchemaBuilder;
use DmLab\TypeSafeCore\Model\Provider\AbstractLlmProvider;
use DmLab\TypeSafeCore\Model\Provider\ProviderSettings;

/**
 * LLM provider over a minimal wire format: body = prompt JSON; reply `{"text": …, "usage": {"in", "out"}}`.
 */
class FakeLlmProvider extends AbstractLlmProvider
{
    public const CODE = 'fake_llm';
    public const URL = 'https://llm.example.test/v1/answer';

    /**
     * @param ProviderSettings $settings
     * @param FakeTransport $transport
     */
    public function __construct(ProviderSettings $settings, FakeTransport $transport)
    {
        parent::__construct(
            $settings,
            new PromptBuilder(),
            new SchemaBuilder(),
            new AnswerMapper(),
            new Backoff(),
            $transport
        );
    }

    /**
     * Reply body of the fake wire format.
     *
     * @param string $text model output
     * @param int $input
     * @param int $output
     * @return string
     */
    public static function reply(string $text, int $input = 50, int $output = 7): string
    {
        return (string)json_encode(['text' => $text, 'usage' => ['in' => $input, 'out' => $output]]);
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
        return 'Fake LLM';
    }

    /**
     * @inheritDoc
     */
    public function isConfigured(): bool
    {
        return true;
    }

    /**
     * @inheritDoc
     */
    public function buildRequest(Request $request, string $model, ?string $repairNote): HttpRequest
    {
        $prompt = $this->prompt($request, new QuestionKeys($request), $repairNote);

        return new HttpRequest('POST', self::URL, [], (string)json_encode(['model' => $model] + $prompt));
    }

    /**
     * @inheritDoc
     */
    public function parseResponse(string $body, Request $request, string $model, bool $cached): Response
    {
        $text = $this->decodeBody($body)['text'] ?? null;
        if (!is_string($text)) {
            throw $this->malformed('no text');
        }

        return $this->response($text, $body, $request, $model, $cached);
    }

    /**
     * @inheritDoc
     */
    public function listModels(): array
    {
        return [];
    }

    /**
     * @inheritDoc
     */
    protected function readUsage(array $data): array
    {
        return ['input' => $data['usage']['in'] ?? 0, 'output' => $data['usage']['out'] ?? 0];
    }
}
