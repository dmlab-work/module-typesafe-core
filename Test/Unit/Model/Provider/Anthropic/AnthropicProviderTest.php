<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypeSafeCore\Test\Unit\Model\Provider\Anthropic;

use DmLab\TypeSafeCore\Api\Data\Answer\ChoiceAnswer;
use DmLab\TypeSafeCore\Api\Data\Answer\NoulAnswer;
use DmLab\TypeSafeCore\Api\Data\Answer\ScoreAnswer;
use DmLab\TypeSafeCore\Api\Data\Response;
use DmLab\TypeSafeCore\Api\ProviderInterface;
use DmLab\TypeSafeCore\Exception\AuthenticationException;
use DmLab\TypeSafeCore\Exception\InvalidAnswerException;
use DmLab\TypeSafeCore\Exception\NotConfiguredException;
use DmLab\TypeSafeCore\Exception\RateLimitException;
use DmLab\TypeSafeCore\Exception\RequestException;
use DmLab\TypeSafeCore\Exception\TransportException;
use DmLab\TypeSafeCore\Model\Config;
use DmLab\TypeSafeCore\Model\Http\Backoff;
use DmLab\TypeSafeCore\Model\Http\HttpResponse;
use DmLab\TypeSafeCore\Model\Llm\AnswerMapper;
use DmLab\TypeSafeCore\Model\Llm\PromptBuilder;
use DmLab\TypeSafeCore\Model\Llm\QuestionKeys;
use DmLab\TypeSafeCore\Model\Llm\SchemaBuilder;
use DmLab\TypeSafeCore\Model\Provider\Anthropic\AnthropicProvider;
use DmLab\TypeSafeCore\Model\Provider\ProviderPool;
use DmLab\TypeSafeCore\Model\Provider\ProviderSettings;
use DmLab\TypeSafeCore\Test\Unit\Fake\FakeTransport;
use DmLab\TypeSafeCore\Test\Unit\Model\Provider\LlmProviderClientTestCase;

class AnthropicProviderTest extends LlmProviderClientTestCase
{
    private const KEY = 'sk-ant-api03-secret';

    protected function setUp(): void
    {
        $this->values['dmlab_typesafe/anthropic/api_key'] = self::KEY;
        parent::setUp();
    }

    protected function code(): string
    {
        return AnthropicProvider::CODE;
    }

    protected function createProvider(
        ProviderSettings $settings,
        PromptBuilder $promptBuilder,
        SchemaBuilder $schemaBuilder,
        AnswerMapper $answerMapper,
        Backoff $backoff,
        FakeTransport $transport
    ): ProviderInterface {
        return new AnthropicProvider($settings, $promptBuilder, $schemaBuilder, $answerMapper, $backoff, $transport);
    }

    public function testIdentityAndDefaults(): void
    {
        $provider = $this->provider();

        $this->assertSame('anthropic', $provider->getCode());
        $this->assertSame('Anthropic API', $provider->getLabel());
        $this->assertSame('claude-haiku-5-5', $provider->getModel());
        $this->assertTrue($provider->isConfigured());
        $this->assertSame(
            'anthropic|https://api.anthropic.com|' . PromptBuilder::PROMPT_VERSION,
            $provider->cacheScope()
        );
    }

    public function testWithoutApiKeyTheClientIsNotConfigured(): void
    {
        unset($this->values['dmlab_typesafe/anthropic/api_key']);

        $this->assertFalse($this->provider()->isConfigured());
        $this->expectException(NotConfiguredException::class);
        $this->client()->evaluate($this->request());
    }

    public function testIsRegisteredInTheDiPool(): void
    {
        $xml = simplexml_load_file(dirname(__DIR__, 5) . '/etc/di.xml');
        $items = $xml->xpath(
            '/config/type[@name="' . ProviderPool::class . '"]/arguments/argument[@name="providers"]'
            . '/item[@name="anthropic"]'
        );

        $this->assertCount(1, $items);
        $this->assertSame(AnthropicProvider::class, (string)$items[0]);
    }

    public function testRequestShapeIsExact(): void
    {
        $this->values[Config::XML_PATH_TIMEOUT] = '45';
        $request = $this->request();
        $this->transport->queue = [$this->reply(200, 'success')];

        $this->client()->evaluate($request);

        [$http] = $this->transport->sent();
        $this->assertSame('POST', $http->getMethod());
        $this->assertSame('https://api.anthropic.com/v1/messages', $http->getUrl());
        $this->assertSame(45, $http->getTimeout());
        $this->assertSame([
            'x-api-key' => self::KEY,
            'anthropic-version' => '2026-10-21',
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'Expect' => '',
        ], $http->getHeaders());

        $keys = new QuestionKeys($request);
        $prompt = new PromptBuilder();
        $this->assertSame([
            'model' => 'claude-haiku-5-5',
            'max_tokens' => 16000,
            'system' => $prompt->system($keys),
            'messages' => [['role' => 'user', 'content' => $prompt->user($request, $keys)]],
            'output_config' => [
                'effort' => 'low',
                'format' => [
                    'type' => 'json_schema',
                    'schema' => (new SchemaBuilder())->build($keys, SchemaBuilder::DIALECT_ANTHROPIC),
                ],
            ],
        ], json_decode((string)$http->getBody(), true));
    }

    public function testRequestNeverSendsTemperatureOrThinking(): void
    {
        $this->values['dmlab_typesafe/openai_compatible/send_temperature'] = '1';
        $this->values['dmlab_typesafe/anthropic/base_url'] = 'https://anthropic-proxy.example.test/';

        $http = $this->provider()->buildRequest($this->request(), 'claude-sonnet-5-5', null);
        $body = json_decode((string)$http->getBody(), true);

        $this->assertSame('https://anthropic-proxy.example.test/v1/messages', $http->getUrl());
        $this->assertSame('claude-sonnet-5-5', $body['model']);
        foreach (['temperature', 'top_p', 'top_k', 'thinking'] as $field) {
            $this->assertArrayNotHasKey($field, $body);
        }
    }

    public function testSuccessMapsAnswersCachesAndRecordsBothTokenCounts(): void
    {
        $request = $this->request();
        $this->transport->queue = [$this->reply(200, 'success')];

        $response = $this->client()->evaluate($request);

        $this->assertSame('claude-haiku-5-5-20261015', $response->getModel());
        $this->assertSame(412, $response->getInputTokens());
        $this->assertFalse($response->isCached());

        $noul = $response->getAnswer('red:rev');
        $this->assertInstanceOf(NoulAnswer::class, $noul);
        $this->assertEqualsWithDelta(0.1, $noul->getProbability(), 1e-9);

        $choice = $response->getAnswer('material');
        $this->assertInstanceOf(ChoiceAnswer::class, $choice);
        $this->assertSame('wool', $choice->getChoice());
        $this->assertSame(0.8, $choice->getConfidence());

        $score = $response->getAnswer('comfort:412');
        $this->assertInstanceOf(ScoreAnswer::class, $score);
        $this->assertSame(2.0, $score->getScore());

        $key = $this->encoder->cacheKey($request, 'claude-haiku-5-5', $this->provider());
        $this->assertSame(
            [[$key, 'catalog_enrich', 'claude-haiku-5-5-20261015', $this->fixture('success'), 412]],
            $this->saved
        );
        $this->assertSame([['catalog_enrich', 412, false, 38]], $this->ledgerRecords);
    }

    public function testCachedBodyIsServedWithoutHttp(): void
    {
        $request = $this->request();
        $this->cached[$this->encoder->cacheKey($request, 'claude-haiku-5-5', $this->provider())]
            = $this->fixture('success');

        $response = $this->client()->evaluate($request);

        $this->assertTrue($response->isCached());
        $this->assertSame(0, $response->getInputTokens());
        $this->assertSame('wool', $response->getAnswer('material')->getChoice());
        $this->assertSame([], $this->transport->calls);
        $this->assertSame([['catalog_enrich', 0, true, 0]], $this->ledgerRecords);
    }

    public function testThinkingBlocksAreIgnoredAndTextBlocksConcatenated(): void
    {
        $response = $this->provider()->parseResponse($this->fixture('thinking'), $this->request(), 'm', false);

        $this->assertEqualsWithDelta(0.1, $response->getAnswer('red:rev')->getProbability(), 1e-9);
        $this->assertSame('wool', $response->getAnswer('material')->getChoice());
        $this->assertSame(2.0, $response->getAnswer('comfort:412')->getScore());
    }

    public function testInvalidJsonIsRepairedOnce(): void
    {
        $this->transport->queue = [$this->reply(200, 'invalid-json'), $this->reply(200, 'success')];

        $response = $this->client()->evaluate($this->request());

        $this->assertSame('wool', $response->getAnswer('material')->getChoice());
        $this->assertWaveSizes([1, 1]);
        [$first, $second] = $this->sentBodies();
        $this->assertSame(
            $first['messages'][0]['content'] . "\n\nYour previous reply was rejected: "
            . 'Invalid answer: the reply is not valid JSON. Reply again with one JSON object that follows the schema.',
            $second['messages'][0]['content']
        );
        $this->assertSame($first['system'], $second['system']);
        $this->assertSame([], $this->clock->sleeps);
        $this->assertCount(1, $this->saved);
        $this->assertSame(
            [['catalog_enrich', 400, false, 21], ['catalog_enrich', 412, false, 38]],
            $this->ledgerRecords
        );
    }

    public function testInvalidJsonTwiceIsFinal(): void
    {
        $this->transport->queue = [$this->reply(200, 'invalid-json'), $this->reply(200, 'invalid-json')];

        $result = $this->client()->evaluateMany([$this->request()])[0];

        $this->assertInstanceOf(InvalidAnswerException::class, $result);
        $this->assertSame('Invalid answer: the reply is not valid JSON.', $result->getMessage());
        $this->assertWaveSizes([1, 1]);
        $this->assertSame([], $this->saved);
    }

    public function testRateLimitHonoursRetryAfterThenSucceeds(): void
    {
        $this->transport->queue = [
            $this->reply(429, 'rate-limit', ['retry-after' => '12']),
            $this->reply(200, 'success'),
        ];

        $this->assertInstanceOf(Response::class, $this->client()->evaluate($this->request()));
        $this->assertSame([12.0], $this->clock->sleeps);
        $this->assertWaveSizes([1, 1]);
    }

    public function testRateLimitOnEveryAttemptIsFinalAndPausesLaterCalls(): void
    {
        $this->maxAttempts = 2;
        $this->transport->handler = fn () => $this->reply(429, 'rate-limit', ['retry-after' => '12']);

        $results = $this->client()->evaluateMany([$this->request('a'), $this->request('b')]);

        $this->assertContainsOnlyInstancesOf(RateLimitException::class, $results);
        $this->assertSame(12, $results[0]->getRetryAfter());
        $this->assertSame('Anthropic API rate limit reached; retry after 12 s.', $results[0]->getMessage());

        $calls = count($this->transport->calls);
        $later = $this->client()->evaluateMany([$this->request('c')])[0];
        $this->assertInstanceOf(RateLimitException::class, $later);
        $this->assertCount($calls, $this->transport->calls);
    }

    /**
     * @return array<string, array{int}>
     */
    public static function authStatusProvider(): array
    {
        return ['authentication_error' => [401], 'permission_error' => [403]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('authStatusProvider')]
    public function testAuthErrorIsFinalAndStopsTheBatch(int $status): void
    {
        $this->concurrency = 1;
        $this->transport->queue = [$this->reply($status, 'auth-error')];

        $results = $this->client()->evaluateMany([$this->request('a'), $this->request('b')]);

        $this->assertContainsOnlyInstancesOf(AuthenticationException::class, $results);
        $this->assertSame("Anthropic API rejected the API key (HTTP $status).", $results[0]->getMessage());
        $this->assertWaveSizes([1]);
        $this->assertSame([], $this->clock->sleeps);
    }

    public function testOverloadedIsRetriedLikeAServerError(): void
    {
        $this->transport->queue = [$this->reply(529, 'overloaded'), $this->reply(200, 'success')];

        $response = $this->client()->evaluate($this->request());

        $this->assertSame('wool', $response->getAnswer('material')->getChoice());
        $this->assertWaveSizes([1, 1]);
        $this->assertCount(1, $this->clock->sleeps);
    }

    public function testOverloadedOnEveryAttemptIsAFinalTransportError(): void
    {
        $this->maxAttempts = 2;
        $this->transport->handler = fn () => $this->reply(529, 'overloaded');

        $result = $this->client()->evaluateMany([$this->request()])[0];

        $this->assertInstanceOf(TransportException::class, $result);
        $this->assertSame(529, $result->getCode());
        $this->assertSame('Anthropic API answered HTTP 529.', $result->getMessage());
        $this->assertWaveSizes([1, 1]);
    }

    public function testRefusalIsAnInvalidAnswer(): void
    {
        $this->transport->queue = [$this->reply(200, 'refusal'), $this->reply(200, 'refusal')];

        $result = $this->client()->evaluateMany([$this->request()])[0];

        $this->assertInstanceOf(InvalidAnswerException::class, $result);
        $this->assertSame('Invalid answer: the model refused to answer.', $result->getMessage());
        $this->assertWaveSizes([1, 1]);
        $this->assertSame(
            [['catalog_enrich', 400, false, 11], ['catalog_enrich', 400, false, 11]],
            $this->ledgerRecords
        );
    }

    public function testMaxTokensStopReasonIsAnInvalidAnswer(): void
    {
        $this->transport->queue = [$this->reply(200, 'max-tokens'), $this->reply(200, 'success')];

        $this->assertInstanceOf(Response::class, $this->client()->evaluate($this->request()));
        $this->assertStringContainsString(
            'Your previous reply was rejected: Invalid answer: the reply ended with stop_reason "max_tokens".',
            $this->sentBodies()[1]['messages'][0]['content']
        );
    }

    /**
     * @return array<string, array{array, string}>
     */
    public static function invalidMessageProvider(): array
    {
        $text = [['type' => 'text', 'text' => '{}']];

        return [
            'pause turn' => [
                ['content' => $text, 'stop_reason' => 'pause_turn'],
                'Invalid answer: the reply ended with stop_reason "pause_turn".',
            ],
            'no stop reason' => [['content' => $text], 'Invalid answer: the reply ended with stop_reason "none".'],
            'only thinking' => [
                ['content' => [['type' => 'thinking', 'thinking' => '{}']], 'stop_reason' => 'end_turn'],
                'Invalid answer: the reply is empty.',
            ],
            'empty content' => [['content' => [], 'stop_reason' => 'end_turn'], 'Invalid answer: the reply is empty.'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidMessageProvider')]
    public function testUnusableMessageIsAnInvalidAnswer(array $message, string $error): void
    {
        $this->expectException(InvalidAnswerException::class);
        $this->expectExceptionMessage($error);
        $this->provider()->parseResponse((string)json_encode($message), $this->request(), 'm', false);
    }

    public function testBodyWithoutContentIsMalformedNotInvalid(): void
    {
        try {
            $this->provider()->parseResponse('{"type":"message"}', $this->request(), 'm', false);
            $this->fail('No exception thrown.');
        } catch (TransportException $e) {
            $this->assertNotInstanceOf(InvalidAnswerException::class, $e);
            $this->assertSame('Anthropic API returned a malformed response: no content', $e->getMessage());
        }
    }

    public function testModelFallsBackToTheRequestedOne(): void
    {
        $body = (string)json_encode([
            'content' => [['type' => 'text', 'text' => '{"q1":{"answer":null,"confidence":0.2},'
                . '"q2":{"choice":"cotton","confidence":1},"q3":{"score":0,"confidence":0.5}}']],
            'stop_reason' => 'end_turn',
        ]);

        $response = $this->provider()->parseResponse($body, $this->request(), 'requested', false);

        $this->assertSame('requested', $response->getModel());
        $this->assertSame(0, $response->getInputTokens());
        $this->assertSame(0.5, $response->getAnswer('red:rev')->getProbability());
    }

    public function testUsageCountsCacheWritesAndReadsAsInput(): void
    {
        $this->assertSame(['input' => 412, 'output' => 38], $this->provider()->usage($this->fixture('success')));
        $this->assertSame(['input' => 400, 'output' => 96], $this->provider()->usage($this->fixture('thinking')));
        $this->assertSame(['input' => 0, 'output' => 0], $this->provider()->usage($this->fixture('auth-error')));
        $this->assertSame(
            ['input' => 5, 'output' => 0],
            $this->provider()->usage('{"usage":{"input_tokens":5,"cache_read_input_tokens":"x","output_tokens":-3}}')
        );
    }

    public function testListModels(): void
    {
        $this->values[Config::XML_PATH_TIMEOUT] = '60';
        $this->transport->queue = [$this->reply(200, 'models')];

        $this->assertSame(
            ['claude-haiku-5-5', 'claude-sonnet-5-5', 'claude-opus-5-5'],
            $this->provider()->listModels()
        );

        [$http] = $this->transport->sent();
        $this->assertSame('GET', $http->getMethod());
        $this->assertSame('https://api.anthropic.com/v1/models?limit=1000', $http->getUrl());
        $this->assertNull($http->getBody());
        $this->assertSame(10, $http->getTimeout());
        $this->assertSame(
            ['x-api-key' => self::KEY, 'anthropic-version' => '2026-10-21', 'Accept' => 'application/json'],
            $http->getHeaders()
        );
    }

    /**
     * @return array<string, array{HttpResponse, class-string, string}>
     */
    public static function listModelsErrorProvider(): array
    {
        $dir = dirname(__DIR__, 3) . '/Fixtures/Provider/anthropic/';

        return [
            'auth' => [
                new HttpResponse(401, [], (string)file_get_contents($dir . 'auth-error.json')),
                AuthenticationException::class,
                'Anthropic API rejected the API key (HTTP 401).',
            ],
            'not found' => [
                new HttpResponse(404, [], 'Not Found'),
                RequestException::class,
                'Anthropic API rejected the request (HTTP 404): Not Found',
            ],
            'transport' => [
                new HttpResponse(0, [], '', 6, 'Could not resolve host'),
                TransportException::class,
                'Anthropic API request failed: Could not resolve host',
            ],
            'no list' => [
                new HttpResponse(200, [], '{"has_more":false}'),
                TransportException::class,
                'Anthropic API returned a malformed response: no models list',
            ],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('listModelsErrorProvider')]
    public function testListModelsErrors(HttpResponse $response, string $class, string $message): void
    {
        $this->transport->queue = [$response];

        $this->expectException($class);
        $this->expectExceptionMessage($message);
        $this->provider()->listModels();
    }
}
