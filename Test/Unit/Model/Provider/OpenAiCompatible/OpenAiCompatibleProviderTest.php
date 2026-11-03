<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypeSafeCore\Test\Unit\Model\Provider\OpenAiCompatible;

use DmLab\TypeSafeCore\Api\Data\Answer\ChoiceAnswer;
use DmLab\TypeSafeCore\Api\Data\Answer\NoulAnswer;
use DmLab\TypeSafeCore\Api\Data\Answer\ScoreAnswer;
use DmLab\TypeSafeCore\Api\Data\Response;
use DmLab\TypeSafeCore\Api\ProviderInterface;
use DmLab\TypeSafeCore\Exception\AuthenticationException;
use DmLab\TypeSafeCore\Exception\BudgetExceededException;
use DmLab\TypeSafeCore\Exception\CircuitOpenException;
use DmLab\TypeSafeCore\Exception\InvalidAnswerException;
use DmLab\TypeSafeCore\Exception\RateLimitException;
use DmLab\TypeSafeCore\Exception\RequestException;
use DmLab\TypeSafeCore\Exception\TransportException;
use DmLab\TypeSafeCore\Model\Config;
use DmLab\TypeSafeCore\Model\Http\Backoff;
use DmLab\TypeSafeCore\Model\Http\CircuitBreaker;
use DmLab\TypeSafeCore\Model\Http\HttpResponse;
use DmLab\TypeSafeCore\Model\Llm\AnswerMapper;
use DmLab\TypeSafeCore\Model\Llm\PromptBuilder;
use DmLab\TypeSafeCore\Model\Llm\QuestionKeys;
use DmLab\TypeSafeCore\Model\Llm\SchemaBuilder;
use DmLab\TypeSafeCore\Model\Provider\OpenAiCompatible\OpenAiCompatibleProvider;
use DmLab\TypeSafeCore\Model\Provider\ProviderPool;
use DmLab\TypeSafeCore\Model\Provider\ProviderSettings;
use DmLab\TypeSafeCore\Test\Unit\Fake\FakeTransport;
use DmLab\TypeSafeCore\Test\Unit\Model\Provider\LlmProviderClientTestCase;

class OpenAiCompatibleProviderTest extends LlmProviderClientTestCase
{
    private const KEY = 'sk-proj-secret';

    protected function setUp(): void
    {
        $this->values['dmlab_typesafe/openai_compatible/api_key'] = self::KEY;
        parent::setUp();
    }

    protected function code(): string
    {
        return OpenAiCompatibleProvider::CODE;
    }

    protected function createProvider(
        ProviderSettings $settings,
        PromptBuilder $promptBuilder,
        SchemaBuilder $schemaBuilder,
        AnswerMapper $answerMapper,
        Backoff $backoff,
        FakeTransport $transport
    ): ProviderInterface {
        return new OpenAiCompatibleProvider(
            $settings,
            $promptBuilder,
            $schemaBuilder,
            $answerMapper,
            $backoff,
            $transport
        );
    }

    public function testIdentityAndDefaults(): void
    {
        $provider = $this->provider();

        $this->assertSame('openai_compatible', $provider->getCode());
        $this->assertSame('OpenAI-compatible API', $provider->getLabel());
        $this->assertSame('gpt-6-luna', $provider->getModel());
        $this->assertTrue($provider->isConfigured());
        $this->assertSame(
            'openai_compatible|https://api.openai.com/v1|' . PromptBuilder::PROMPT_VERSION,
            $provider->cacheScope()
        );
    }

    public function testSelfHostedBaseUrlIsConfiguredWithoutAnApiKey(): void
    {
        unset($this->values['dmlab_typesafe/openai_compatible/api_key']);
        $this->values['dmlab_typesafe/openai_compatible/base_url'] = 'http://localhost:11434/v1';

        $this->assertTrue($this->provider()->isConfigured());
    }

    public function testHostedDefaultBaseUrlNeedsAnApiKey(): void
    {
        unset($this->values['dmlab_typesafe/openai_compatible/api_key']);

        $this->assertFalse($this->provider()->isConfigured());
    }

    public function testIsRegisteredInTheDiPool(): void
    {
        $xml = simplexml_load_file(dirname(__DIR__, 5) . '/etc/di.xml');
        $items = $xml->xpath(
            '/config/type[@name="' . ProviderPool::class . '"]/arguments/argument[@name="providers"]'
            . '/item[@name="openai_compatible"]'
        );

        $this->assertCount(1, $items);
        $this->assertSame(OpenAiCompatibleProvider::class, (string)$items[0]);
    }

    public function testRequestShapeIsExact(): void
    {
        $this->values[Config::XML_PATH_TIMEOUT] = '45';
        $request = $this->request();
        $this->transport->queue = [$this->reply(200, 'success')];

        $this->client()->evaluate($request);

        [$http] = $this->transport->sent();
        $this->assertSame('POST', $http->getMethod());
        $this->assertSame('https://api.openai.com/v1/chat/completions', $http->getUrl());
        $this->assertSame(45, $http->getTimeout());
        $this->assertSame([
            'Authorization' => 'Bearer ' . self::KEY,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'Expect' => '',
        ], $http->getHeaders());

        $keys = new QuestionKeys($request);
        $prompt = new PromptBuilder();
        $this->assertSame([
            'model' => 'gpt-6-luna',
            'messages' => [
                ['role' => 'system', 'content' => $prompt->system($keys)],
                ['role' => 'user', 'content' => $prompt->user($request, $keys)],
            ],
            'response_format' => [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => 'answers',
                    'strict' => true,
                    'schema' => (new SchemaBuilder())->build($keys, SchemaBuilder::DIALECT_OPENAI),
                ],
            ],
            'max_completion_tokens' => 16000,
        ], json_decode((string)$http->getBody(), true));
    }

    public function testTemperatureSwitchOnSendsTemperatureZero(): void
    {
        $this->values['dmlab_typesafe/openai_compatible/send_temperature'] = '1';

        $body = json_decode((string)$this->provider()->buildRequest($this->request(), 'm', null)->getBody(), true);

        $this->assertSame(0, $body['temperature']);
        $this->assertSame(16000, $body['max_completion_tokens']);
    }

    public function testCustomBaseUrlAndEmptyKeySendNoAuthHeaders(): void
    {
        unset($this->values['dmlab_typesafe/openai_compatible/api_key']);
        $this->values['dmlab_typesafe/openai_compatible/base_url'] = 'http://ollama.local:11434/v1/';

        $http = $this->provider()->buildRequest($this->request(), 'llama4', null);

        $this->assertSame('http://ollama.local:11434/v1/chat/completions', $http->getUrl());
        $this->assertSame(
            ['Content-Type' => 'application/json', 'Accept' => 'application/json', 'Expect' => ''],
            $http->getHeaders()
        );
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function azureHostProvider(): array
    {
        return [
            'azure openai' => ['https://shop-eu.openai.azure.com/openai/v1', true],
            'azure ai foundry' => ['https://shop.services.ai.azure.com/openai/v1', true],
            'upper case host' => ['https://Shop.OpenAI.Azure.com/openai/v1', true],
            'look-alike host' => ['https://openai.azure.com.example.test/v1', false],
            'openrouter' => ['https://openrouter.ai/api/v1', false],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('azureHostProvider')]
    public function testAzureHostsAlsoGetTheApiKeyHeader(string $baseUrl, bool $azure): void
    {
        $this->values['dmlab_typesafe/openai_compatible/base_url'] = $baseUrl;

        $headers = $this->provider()->buildRequest($this->request(), 'gpt-6-luna', null)->getHeaders();

        $this->assertSame('Bearer ' . self::KEY, $headers['Authorization']);
        $this->assertSame($azure ? self::KEY : null, $headers['api-key'] ?? null);
    }

    public function testSuccessMapsAnswersCachesAndRecordsBothTokenCounts(): void
    {
        $request = $this->request();
        $this->transport->queue = [$this->reply(200, 'success')];

        $response = $this->client()->evaluate($request);

        $this->assertSame('gpt-6-luna-2026-09-15', $response->getModel());
        $this->assertSame(412, $response->getInputTokens());
        $this->assertFalse($response->isCached());

        $noul = $response->getAnswer('red:rev');
        $this->assertInstanceOf(NoulAnswer::class, $noul);
        $this->assertEqualsWithDelta(0.1, $noul->getProbability(), 1e-9);

        $choice = $response->getAnswer('material');
        $this->assertInstanceOf(ChoiceAnswer::class, $choice);
        $this->assertSame('wool', $choice->getChoice());
        $this->assertSame(0.8, $choice->getConfidence());
        $this->assertEqualsWithDelta(0.2, $choice->getProbability('cotton'), 1e-9);

        $score = $response->getAnswer('comfort:412');
        $this->assertInstanceOf(ScoreAnswer::class, $score);
        $this->assertSame(2.0, $score->getScore());
        $this->assertSame(0.7, $score->getConfidence());

        $key = $this->encoder->cacheKey($request, 'gpt-6-luna', $this->provider());
        $this->assertCount(1, $this->saved);
        $this->assertSame(
            [$key, 'catalog_enrich', 'gpt-6-luna-2026-09-15', $this->fixture('success'), 412],
            $this->saved[0]
        );
        $this->assertSame([['catalog_enrich', 412, false, 38]], $this->ledgerRecords);
    }

    public function testCachedBodyIsServedWithoutHttp(): void
    {
        $request = $this->request();
        $this->cached[$this->encoder->cacheKey($request, 'gpt-6-luna', $this->provider())] = $this->fixture('success');

        $response = $this->client()->evaluate($request);

        $this->assertTrue($response->isCached());
        $this->assertSame(0, $response->getInputTokens());
        $this->assertSame('wool', $response->getAnswer('material')->getChoice());
        $this->assertSame([], $this->transport->calls);
        $this->assertSame([['catalog_enrich', 0, true, 0]], $this->ledgerRecords);
    }

    public function testInvalidJsonIsRepairedOnce(): void
    {
        $this->transport->queue = [$this->reply(200, 'invalid-json'), $this->reply(200, 'success')];

        $response = $this->client()->evaluate($this->request());

        $this->assertSame('wool', $response->getAnswer('material')->getChoice());
        $this->assertWaveSizes([1, 1]);
        [$first, $second] = $this->sentBodies();
        $this->assertSame(
            $first['messages'][1]['content'] . "\n\nYour previous reply was rejected: "
            . 'Invalid answer: the reply is not valid JSON. Reply again with one JSON object that follows the schema.',
            $second['messages'][1]['content']
        );
        $this->assertSame($first['messages'][0], $second['messages'][0]);
        $this->assertSame([], $this->clock->sleeps);
        $this->assertCount(1, $this->saved);
        $this->assertSame(
            [['catalog_enrich', 412, false, 21], ['catalog_enrich', 412, false, 38]],
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
            $this->reply(429, 'rate-limit', ['retry-after' => '7']),
            $this->reply(200, 'success'),
        ];

        $this->assertInstanceOf(Response::class, $this->client()->evaluate($this->request()));
        $this->assertSame([7.0], $this->clock->sleeps);
        $this->assertWaveSizes([1, 1]);
    }

    public function testRateLimitOnEveryAttemptIsFinalAndPausesLaterCalls(): void
    {
        $this->maxAttempts = 2;
        $this->transport->handler = fn () => $this->reply(429, 'rate-limit', ['retry-after' => '7']);

        $results = $this->client()->evaluateMany([$this->request('a'), $this->request('b')]);

        $this->assertContainsOnlyInstancesOf(RateLimitException::class, $results);
        $this->assertSame(7, $results[0]->getRetryAfter());
        $this->assertSame('OpenAI-compatible API rate limit reached; retry after 7 s.', $results[0]->getMessage());

        $calls = count($this->transport->calls);
        $later = $this->client()->evaluateMany([$this->request('c')])[0];
        $this->assertInstanceOf(RateLimitException::class, $later);
        $this->assertCount($calls, $this->transport->calls);
    }

    public function testAuthErrorIsFinalAndStopsTheBatch(): void
    {
        $this->concurrency = 1;
        $this->transport->queue = [$this->reply(401, 'auth-error')];

        $results = $this->client()->evaluateMany([$this->request('a'), $this->request('b')]);

        $this->assertContainsOnlyInstancesOf(AuthenticationException::class, $results);
        $this->assertSame('OpenAI-compatible API rejected the API key (HTTP 401).', $results[0]->getMessage());
        $this->assertWaveSizes([1]);
        $this->assertSame([], $this->clock->sleeps);
    }

    public function testQuotaErrorIsAFinalRequestErrorNotARateLimit(): void
    {
        $this->transport->handler = fn () => $this->reply(429, 'quota');

        $results = $this->client()->evaluateMany([$this->request('a'), $this->request('b')]);

        foreach ($results as $result) {
            $this->assertInstanceOf(RequestException::class, $result);
            $this->assertNotInstanceOf(RateLimitException::class, $result);
            $this->assertSame(429, $result->getCode());
            $this->assertSame(
                'OpenAI-compatible API quota is used up; check the plan and billing of the account (HTTP 429).',
                $result->getMessage()
            );
        }
        $this->assertWaveSizes([2]);
        $this->assertSame([], $this->clock->sleeps);
        $this->assertSame([], $this->circuitCache->data);
    }

    public function testQuotaErrorStopsTheBatchAfterTheFirstWave(): void
    {
        $this->concurrency = 2;
        $this->transport->handler = fn () => $this->reply(429, 'quota');
        $requests = [];
        for ($i = 0; $i < 5; $i++) {
            $requests[] = $this->request('sofa ' . $i);
        }

        $results = $this->client()->evaluateMany($requests);

        foreach ($results as $result) {
            $this->assertInstanceOf(RequestException::class, $result);
            $this->assertSame(429, $result->getCode());
        }
        $this->assertWaveSizes([2]);
    }

    public function testMissesGoOutInWavesOfConcurrencyAndBudgetIsRecheckedPerWave(): void
    {
        $this->budgets = [null, 100, 0];
        $this->transport->handler = fn () => $this->reply(200, 'success');
        $requests = [];
        for ($i = 0; $i < 10; $i++) {
            $requests[] = $this->request('sofa ' . $i);
        }

        $results = $this->client()->evaluateMany($requests);

        $this->assertWaveSizes([4, 4]);
        $this->assertContainsOnlyInstancesOf(Response::class, array_slice($results, 0, 8));
        $this->assertContainsOnlyInstancesOf(BudgetExceededException::class, array_slice($results, 8));
        $this->assertSame(
            'OpenAI-compatible API monthly token budget (1000) is used up.',
            $results[8]->getMessage()
        );
        $this->assertCount(8, $this->ledgerRecords);
        $this->assertSame(['catalog_enrich', 412, false, 38], $this->ledgerRecords[0]);
    }

    public function testExhaustedBudgetSendsNothingButServesCacheHits(): void
    {
        $this->budgets = [0];
        $hit = $this->request('cached');
        $this->cached[$this->encoder->cacheKey($hit, 'gpt-6-luna', $this->provider())] = $this->fixture('success');

        $results = $this->client()->evaluateMany(['hit' => $hit, 'miss' => $this->request('fresh')]);

        $this->assertTrue($results['hit']->isCached());
        $this->assertInstanceOf(BudgetExceededException::class, $results['miss']);
        $this->assertSame([], $this->transport->calls);
    }

    public function testServerErrorIsRetriedWithBackoff(): void
    {
        $this->transport->queue = [new HttpResponse(503), $this->reply(200, 'success')];

        $this->assertInstanceOf(Response::class, $this->client()->evaluate($this->request()));
        $this->assertWaveSizes([1, 1]);
        $this->assertCount(1, $this->clock->sleeps);
    }

    public function testRepeatedServerErrorsOpenTheCircuit(): void
    {
        $this->maxAttempts = 1;
        $this->transport->handler = static fn () => new HttpResponse(503);

        for ($i = 0; $i < CircuitBreaker::THRESHOLD; $i++) {
            $this->assertInstanceOf(
                TransportException::class,
                $this->client()->evaluateMany([$this->request('sofa ' . $i)])[0]
            );
        }

        $result = $this->client()->evaluateMany([$this->request('next')])[0];

        $this->assertInstanceOf(CircuitOpenException::class, $result);
        $this->assertSame(
            'OpenAI-compatible API calls are paused for up to 60 s after repeated failures.',
            $result->getMessage()
        );
        $this->assertCount(CircuitBreaker::THRESHOLD, $this->transport->calls);
    }

    public function testRefusalIsAnInvalidAnswer(): void
    {
        $this->transport->queue = [$this->reply(200, 'refusal'), $this->reply(200, 'refusal')];

        $result = $this->client()->evaluateMany([$this->request()])[0];

        $this->assertInstanceOf(InvalidAnswerException::class, $result);
        $this->assertSame('Invalid answer: the model refused to answer.', $result->getMessage());
        $this->assertWaveSizes([1, 1]);
        $this->assertSame(
            [['catalog_enrich', 412, false, 11], ['catalog_enrich', 412, false, 11]],
            $this->ledgerRecords
        );
    }

    public function testFinishReasonLengthIsAnInvalidAnswer(): void
    {
        $this->transport->queue = [$this->reply(200, 'length'), $this->reply(200, 'success')];

        $this->assertInstanceOf(Response::class, $this->client()->evaluate($this->request()));
        $this->assertStringContainsString(
            'Your previous reply was rejected: Invalid answer: the reply ended with finish_reason "length".',
            $this->sentBodies()[1]['messages'][1]['content']
        );
    }

    /**
     * @return array<string, array{array, string}>
     */
    public static function invalidChoiceProvider(): array
    {
        $choice = ['index' => 0, 'message' => ['role' => 'assistant', 'content' => '{}', 'refusal' => null]];

        return [
            'content filter' => [
                [$choice + ['finish_reason' => 'content_filter']],
                'Invalid answer: the reply ended with finish_reason "content_filter".',
            ],
            'no finish reason' => [[$choice], 'Invalid answer: the reply ended with finish_reason "none".'],
            'null content' => [
                [['message' => ['content' => null, 'refusal' => null], 'finish_reason' => 'stop']],
                'Invalid answer: the reply is empty.',
            ],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidChoiceProvider')]
    public function testUnusableChoiceIsAnInvalidAnswer(array $choices, string $message): void
    {
        $this->expectException(InvalidAnswerException::class);
        $this->expectExceptionMessage($message);
        $this->provider()->parseResponse((string)json_encode(['choices' => $choices]), $this->request(), 'm', false);
    }

    public function testBodyWithoutChoicesIsMalformedNotInvalid(): void
    {
        try {
            $this->provider()->parseResponse('{"choices":[]}', $this->request(), 'm', false);
            $this->fail('No exception thrown.');
        } catch (TransportException $e) {
            $this->assertNotInstanceOf(InvalidAnswerException::class, $e);
            $this->assertSame('OpenAI-compatible API returned a malformed response: no choices', $e->getMessage());
        }
    }

    public function testModelFallsBackToTheRequestedOne(): void
    {
        $body = (string)json_encode(['choices' => [[
            'message' => ['content' => '{"q1":{"answer":null,"confidence":0.2},"q2":{"choice":"cotton",'
                . '"confidence":1},"q3":{"score":0,"confidence":0.5}}'],
            'finish_reason' => 'stop',
        ]]]);

        $response = $this->provider()->parseResponse($body, $this->request(), 'requested', false);

        $this->assertSame('requested', $response->getModel());
        $this->assertSame(0, $response->getInputTokens());
        $this->assertSame(0.5, $response->getAnswer('red:rev')->getProbability());
    }

    public function testUsage(): void
    {
        $this->assertSame(['input' => 412, 'output' => 38], $this->provider()->usage($this->fixture('success')));
        $this->assertSame(['input' => 0, 'output' => 0], $this->provider()->usage($this->fixture('auth-error')));
    }

    public function testListModels(): void
    {
        $this->values[Config::XML_PATH_TIMEOUT] = '60';
        $this->values['dmlab_typesafe/openai_compatible/base_url'] = 'https://shop.openai.azure.com/openai/v1';
        $this->transport->queue = [$this->reply(200, 'models')];

        $this->assertSame(['gpt-6-luna', 'gpt-6', 'text-embedding-4-small'], $this->provider()->listModels());

        [$http] = $this->transport->sent();
        $this->assertSame('GET', $http->getMethod());
        $this->assertSame('https://shop.openai.azure.com/openai/v1/models', $http->getUrl());
        $this->assertNull($http->getBody());
        $this->assertSame(10, $http->getTimeout());
        $this->assertSame(
            ['Authorization' => 'Bearer ' . self::KEY, 'api-key' => self::KEY, 'Accept' => 'application/json'],
            $http->getHeaders()
        );
    }

    public function testSelfHostedListModelsWithoutKeySendsNoAuthorization(): void
    {
        unset($this->values['dmlab_typesafe/openai_compatible/api_key']);
        $this->values['dmlab_typesafe/openai_compatible/base_url'] = 'http://localhost:11434/v1';
        $this->transport->queue = [$this->reply(200, 'models')];

        $this->provider()->listModels();

        $this->assertSame(['Accept' => 'application/json'], $this->transport->sent()[0]->getHeaders());
    }

    /**
     * @return array<string, array{HttpResponse, class-string, string}>
     */
    public static function listModelsErrorProvider(): array
    {
        $dir = dirname(__DIR__, 3) . '/Fixtures/Provider/openai_compatible/';

        return [
            'auth' => [
                new HttpResponse(401, [], (string)file_get_contents($dir . 'auth-error.json')),
                AuthenticationException::class,
                'OpenAI-compatible API rejected the API key (HTTP 401).',
            ],
            'quota' => [
                new HttpResponse(429, [], (string)file_get_contents($dir . 'quota.json')),
                RequestException::class,
                'OpenAI-compatible API quota is used up; check the plan and billing of the account (HTTP 429).',
            ],
            'not found' => [
                new HttpResponse(404, [], 'Not Found'),
                RequestException::class,
                'OpenAI-compatible API rejected the request (HTTP 404): Not Found',
            ],
            'transport' => [
                new HttpResponse(0, [], '', 7, 'Connection refused'),
                TransportException::class,
                'OpenAI-compatible API request failed: Connection refused',
            ],
            'no list' => [
                new HttpResponse(200, [], '{"object":"list"}'),
                TransportException::class,
                'OpenAI-compatible API returned a malformed response: no models list',
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
