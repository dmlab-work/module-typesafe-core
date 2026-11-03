<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypeSafeCore\Test\Unit\Model\Provider;

use DmLab\TypeSafeCore\Api\Data\Answer\NoulAnswer;
use DmLab\TypeSafeCore\Api\Data\Question\NoulQuestion;
use DmLab\TypeSafeCore\Api\Data\Request;
use DmLab\TypeSafeCore\Api\Data\Response;
use DmLab\TypeSafeCore\Exception\InvalidAnswerException;
use DmLab\TypeSafeCore\Exception\RequestException;
use DmLab\TypeSafeCore\Exception\TransportException;
use DmLab\TypeSafeCore\Model\Http\Backoff;
use DmLab\TypeSafeCore\Model\Http\HttpRequest;
use DmLab\TypeSafeCore\Model\Http\HttpResponse;
use DmLab\TypeSafeCore\Model\Llm\AnswerMapper;
use DmLab\TypeSafeCore\Model\Llm\PromptBuilder;
use DmLab\TypeSafeCore\Model\Llm\QuestionKeys;
use DmLab\TypeSafeCore\Model\Llm\SchemaBuilder;
use DmLab\TypeSafeCore\Model\Provider\AbstractLlmProvider;
use DmLab\TypeSafeCore\Model\Provider\ProviderSettings;
use DmLab\TypeSafeCore\Test\Unit\Fake\FakeTransport;
use Magento\Framework\App\Config\ScopeConfigInterface;
use PHPUnit\Framework\TestCase;

class AbstractLlmProviderTest extends TestCase
{
    private const USAGE_BODY = '{"usage":{"in":120,"out":30},"text":"x"}';

    /** @var array<string, string> */
    private array $values = [
        'dmlab_typesafe/anthropic/base_url' => 'https://llm.example.test/',
        'dmlab_typesafe/anthropic/model' => 'model-x',
    ];

    private function provider(): AbstractLlmProvider
    {
        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(fn (string $path) => $this->values[$path] ?? null);

        return new class (
            new ProviderSettings($scopeConfig),
            new PromptBuilder(),
            new SchemaBuilder(),
            new AnswerMapper(),
            new Backoff(),
            new FakeTransport()
        ) extends AbstractLlmProvider {
            public function getCode(): string
            {
                return 'anthropic';
            }

            public function getLabel(): string
            {
                return 'Test LLM';
            }

            public function isConfigured(): bool
            {
                return true;
            }

            public function buildRequest(Request $request, string $model, ?string $repairNote): HttpRequest
            {
                $prompt = $this->prompt($request, new QuestionKeys($request), $repairNote);

                return new HttpRequest('POST', 'https://llm.example.test', [], json_encode($prompt));
            }

            public function parseResponse(string $body, Request $request, string $model, bool $cached): Response
            {
                $text = (string)($this->decodeBody($body)['text'] ?? '');

                return $this->response($text, $body, $request, $model, $cached);
            }

            public function listModels(): array
            {
                return [];
            }

            protected function readUsage(array $data): array
            {
                return ['input' => $data['usage']['in'] ?? null, 'output' => $data['usage']['out'] ?? null];
            }
        };
    }

    private function request(array $state = ['title' => 'Red sofa']): Request
    {
        return new Request($state, ['red:rev' => new NoulQuestion('Red?')], 'catalog_enrich');
    }

    private function body(string $text): string
    {
        return json_encode(['usage' => ['in' => 50, 'out' => 7], 'text' => $text]);
    }

    public function testModelAndCacheScope(): void
    {
        $provider = $this->provider();

        self::assertSame('model-x', $provider->getModel());
        self::assertSame(
            'anthropic|https://llm.example.test|' . PromptBuilder::PROMPT_VERSION,
            $provider->cacheScope()
        );

        $this->values['dmlab_typesafe/anthropic/base_url'] = 'https://other.example.test';
        self::assertSame('anthropic|https://other.example.test|1', $this->provider()->cacheScope());
    }

    public function testUsage(): void
    {
        $provider = $this->provider();

        self::assertSame(['input' => 120, 'output' => 30], $provider->usage(self::USAGE_BODY));
        self::assertSame(['input' => 0, 'output' => 0], $provider->usage('not json'));
        self::assertSame(['input' => 0, 'output' => 0], $provider->usage('"string"'));
        self::assertSame(['input' => 0, 'output' => 0], $provider->usage('{"usage":{"in":-5,"out":"x"}}'));
        self::assertSame(['input' => 9, 'output' => 0], $provider->usage('{"usage":{"in":"9"}}'));
    }

    public function testRetryAfterAndDefaultErrorMapping(): void
    {
        $provider = $this->provider();

        self::assertSame(17, $provider->retryAfter(new HttpResponse(429, ['Retry-After' => '17'])));
        self::assertNull($provider->retryAfter(new HttpResponse(429)));
        self::assertNull($provider->classifyError(new HttpResponse(500)));
    }

    public function testPromptCarriesRepairNote(): void
    {
        $body = (string)$this->provider()->buildRequest($this->request(), 'model-x', 'Invalid answer: x.')->getBody();
        $prompt = json_decode($body, true);

        self::assertStringContainsString('Type "noul"', $prompt['system']);
        self::assertStringStartsWith('{"state":{"title":"Red sofa"},"questions":{"q1":', $prompt['user']);
        self::assertStringContainsString("\n\nYour previous reply was rejected: Invalid answer: x.", $prompt['user']);
    }

    public function testUnencodableRequestIsARequestException(): void
    {
        $this->expectException(RequestException::class);
        $this->expectExceptionMessage('Test LLM request could not be encoded: ');
        $this->provider()->buildRequest($this->request(['x' => NAN]), 'model-x', null);
    }

    public function testResponseMapsAnswersAndTokens(): void
    {
        $text = '{"q1":{"answer":true,"confidence":0.8}}';
        $response = $this->provider()->parseResponse($this->body($text), $this->request(), 'model-x', false);

        self::assertSame('model-x', $response->getModel());
        self::assertSame(50, $response->getInputTokens());
        self::assertFalse($response->isCached());
        $answer = $response->getAnswer('red:rev');
        self::assertInstanceOf(NoulAnswer::class, $answer);
        self::assertSame(0.8, $answer->getProbability());

        $cached = $this->provider()->parseResponse($this->body($text), $this->request(), 'model-x', true);
        self::assertSame(0, $cached->getInputTokens());
        self::assertTrue($cached->isCached());
    }

    public function testResponseAcceptsAMarkdownFence(): void
    {
        $text = "```json\n{\"q1\":{\"answer\":false,\"confidence\":0.6}}\n```";
        $response = $this->provider()->parseResponse($this->body($text), $this->request(), 'model-x', false);

        self::assertEqualsWithDelta(0.4, $response->getAnswer('red:rev')->getProbability(), 1e-9);
    }

    public function testInvalidJsonOutputIsAnInvalidAnswer(): void
    {
        $this->expectException(InvalidAnswerException::class);
        $this->expectExceptionMessage('Invalid answer: the reply is not valid JSON.');
        $this->provider()->parseResponse($this->body('{"q1": tru'), $this->request(), 'model-x', false);
    }

    public function testSchemaViolationIsAnInvalidAnswer(): void
    {
        $this->expectException(InvalidAnswerException::class);
        $this->expectExceptionMessage('Invalid answer: q1.answer must be true, false or null.');
        $this->provider()->parseResponse(
            $this->body('{"q1":{"answer":"yes","confidence":0.5}}'),
            $this->request(),
            'model-x',
            false
        );
    }

    public function testMalformedBodyIsATransportErrorNotAnInvalidAnswer(): void
    {
        foreach (['<html>', '42'] as $body) {
            try {
                $this->provider()->parseResponse($body, $this->request(), 'model-x', false);
                self::fail('Expected TransportException.');
            } catch (TransportException $e) {
                self::assertNotInstanceOf(InvalidAnswerException::class, $e);
                self::assertStringStartsWith('Test LLM returned a malformed response: ', $e->getMessage());
            }
        }
    }
}
