<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypeSafeCore\Test\Unit\Model\Provider\Gemini;

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
use DmLab\TypeSafeCore\Model\Provider\Gemini\GeminiProvider;
use DmLab\TypeSafeCore\Model\Provider\ProviderPool;
use DmLab\TypeSafeCore\Model\Provider\ProviderSettings;
use DmLab\TypeSafeCore\Test\Unit\Fake\FakeTransport;
use DmLab\TypeSafeCore\Test\Unit\Model\Provider\LlmProviderClientTestCase;

class GeminiProviderTest extends LlmProviderClientTestCase
{
    private const KEY = 'AIzaSyD-secret-key';

    protected function setUp(): void
    {
        $this->values['dmlab_typesafe/gemini/api_key'] = self::KEY;
        parent::setUp();
    }

    protected function code(): string
    {
        return GeminiProvider::CODE;
    }

    protected function createProvider(
        ProviderSettings $settings,
        PromptBuilder $promptBuilder,
        SchemaBuilder $schemaBuilder,
        AnswerMapper $answerMapper,
        Backoff $backoff,
        FakeTransport $transport
    ): ProviderInterface {
        return new GeminiProvider($settings, $promptBuilder, $schemaBuilder, $answerMapper, $backoff, $transport);
    }

    public function testIdentityAndDefaults(): void
    {
        $provider = $this->provider();

        $this->assertSame('gemini', $provider->getCode());
        $this->assertSame('Gemini API', $provider->getLabel());
        $this->assertSame('gemini-3.5-flash-lite', $provider->getModel());
        $this->assertTrue($provider->isConfigured());
        $this->assertSame(
            'gemini|https://generativelanguage.googleapis.com|' . PromptBuilder::PROMPT_VERSION,
            $provider->cacheScope()
        );
    }

    public function testWithoutApiKeyTheClientIsNotConfigured(): void
    {
        unset($this->values['dmlab_typesafe/gemini/api_key']);

        $this->assertFalse($this->provider()->isConfigured());
        $this->expectException(NotConfiguredException::class);
        $this->client()->evaluate($this->request());
    }

    public function testIsRegisteredInTheDiPool(): void
    {
        $xml = simplexml_load_file(dirname(__DIR__, 5) . '/etc/di.xml');
        $items = $xml->xpath(
            '/config/type[@name="' . ProviderPool::class . '"]/arguments/argument[@name="providers"]'
            . '/item[@name="gemini"]'
        );

        $this->assertCount(1, $items);
        $this->assertSame(GeminiProvider::class, (string)$items[0]);
    }

    public function testRequestShapeIsExactAndTheKeyIsOnlyInTheHeader(): void
    {
        $this->values[Config::XML_PATH_TIMEOUT] = '45';
        $request = $this->request();
        $this->transport->queue = [$this->reply(200, 'success')];

        $this->client()->evaluate($request);

        [$http] = $this->transport->sent();
        $this->assertSame('POST', $http->getMethod());
        $this->assertSame(
            'https://generativelanguage.googleapis.com/v1beta/models/gemini-3.5-flash-lite:generateContent',
            $http->getUrl()
        );
        $this->assertStringNotContainsString(self::KEY, $http->getUrl());
        $this->assertStringNotContainsString(self::KEY, (string)$http->getBody());
        $this->assertSame(45, $http->getTimeout());
        $this->assertSame([
            'x-goog-api-key' => self::KEY,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'Expect' => '',
        ], $http->getHeaders());

        $keys = new QuestionKeys($request);
        $prompt = new PromptBuilder();
        $this->assertSame([
            'systemInstruction' => ['parts' => [['text' => $prompt->system($keys)]]],
            'contents' => [['role' => 'user', 'parts' => [['text' => $prompt->user($request, $keys)]]]],
            'generationConfig' => [
                'temperature' => 0,
                'responseMimeType' => 'application/json',
                'responseSchema' => (new SchemaBuilder())->build($keys, SchemaBuilder::DIALECT_GEMINI),
                'maxOutputTokens' => 16000,
            ],
        ], json_decode((string)$http->getBody(), true));
    }

    public function testCustomBaseUrlAndPrefixedModel(): void
    {
        $this->values['dmlab_typesafe/gemini/base_url'] = 'https://gemini-proxy.example.test/';

        $http = $this->provider()->buildRequest($this->request(), 'models/gemini-3.5-pro', null);

        $this->assertSame(
            'https://gemini-proxy.example.test/v1beta/models/gemini-3.5-pro:generateContent',
            $http->getUrl()
        );
    }

    public function testSuccessMapsAnswersCachesAndRecordsBothTokenCounts(): void
    {
        $request = $this->request();
        $this->transport->queue = [$this->reply(200, 'success')];

        $response = $this->client()->evaluate($request);

        $this->assertSame('gemini-3.5-flash-lite-001', $response->getModel());
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

        $key = $this->encoder->cacheKey($request, 'gemini-3.5-flash-lite', $this->provider());
        $this->assertSame(
            [[$key, 'catalog_enrich', 'gemini-3.5-flash-lite-001', $this->fixture('success'), 412]],
            $this->saved
        );
        $this->assertSame([['catalog_enrich', 412, false, 38]], $this->ledgerRecords);
    }

    public function testCachedBodyIsServedWithoutHttp(): void
    {
        $request = $this->request();
        $this->cached[$this->encoder->cacheKey($request, 'gemini-3.5-flash-lite', $this->provider())]
            = $this->fixture('success');

        $response = $this->client()->evaluate($request);

        $this->assertTrue($response->isCached());
        $this->assertSame(0, $response->getInputTokens());
        $this->assertSame('wool', $response->getAnswer('material')->getChoice());
        $this->assertSame([], $this->transport->calls);
        $this->assertSame([['catalog_enrich', 0, true, 0]], $this->ledgerRecords);
    }

    public function testThoughtPartsAreSkippedAndTextPartsConcatenated(): void
    {
        $response = $this->provider()->parseResponse($this->fixture('thought'), $this->request(), 'm', false);

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
            $first['contents'][0]['parts'][0]['text'] . "\n\nYour previous reply was rejected: "
            . 'Invalid answer: the reply is not valid JSON. Reply again with one JSON object that follows the schema.',
            $second['contents'][0]['parts'][0]['text']
        );
        $this->assertSame($first['systemInstruction'], $second['systemInstruction']);
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

    public function testRateLimitHonoursTheBodyRetryDelayThenSucceeds(): void
    {
        $this->transport->queue = [$this->reply(429, 'rate-limit'), $this->reply(200, 'success')];

        $this->assertInstanceOf(Response::class, $this->client()->evaluate($this->request()));
        $this->assertSame([17.0], $this->clock->sleeps);
        $this->assertWaveSizes([1, 1]);
    }

    public function testRateLimitOnEveryAttemptIsFinalAndPausesLaterCalls(): void
    {
        $this->maxAttempts = 2;
        $this->transport->handler = fn () => $this->reply(429, 'rate-limit');

        $results = $this->client()->evaluateMany([$this->request('a'), $this->request('b')]);

        $this->assertContainsOnlyInstancesOf(RateLimitException::class, $results);
        $this->assertSame(17, $results[0]->getRetryAfter());
        $this->assertSame('Gemini API rate limit reached; retry after 17 s.', $results[0]->getMessage());

        $calls = count($this->transport->calls);
        $later = $this->client()->evaluateMany([$this->request('c')])[0];
        $this->assertInstanceOf(RateLimitException::class, $later);
        $this->assertCount($calls, $this->transport->calls);
    }

    /**
     * @return array<string, array{array<string, string>, string, int|null}>
     */
    public static function retryAfterProvider(): array
    {
        $info = static fn (string $delay): string => (string)json_encode(['error' => ['details' => [
            ['@type' => 'type.googleapis.com/google.rpc.Help'],
            ['@type' => 'type.googleapis.com/google.rpc.RetryInfo', 'retryDelay' => $delay],
        ]]]);

        return [
            'header wins' => [['retry-after' => '5'], $info('17s'), 5],
            'body seconds' => [[], $info('17s'), 17],
            'fractional rounds up' => [[], $info('17.2s'), 18],
            'unparseable delay' => [[], $info('soon'), null],
            'no details' => [[], '{"error":{"code":429}}', null],
            'not json' => [[], 'Too Many Requests', null],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('retryAfterProvider')]
    public function testRetryAfter(array $headers, string $body, ?int $expected): void
    {
        $this->assertSame($expected, $this->provider()->retryAfter(new HttpResponse(429, $headers, $body)));
    }

    /**
     * @return array<string, array{int, string}>
     */
    public static function authErrorProvider(): array
    {
        return ['API_KEY_INVALID' => [400, 'auth-error'], 'PERMISSION_DENIED' => [403, 'permission-denied']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('authErrorProvider')]
    public function testAuthErrorIsFinalAndStopsTheBatch(int $status, string $fixture): void
    {
        $this->concurrency = 1;
        $this->transport->queue = [$this->reply($status, $fixture)];

        $results = $this->client()->evaluateMany([$this->request('a'), $this->request('b')]);

        $this->assertContainsOnlyInstancesOf(AuthenticationException::class, $results);
        $this->assertSame("Gemini API rejected the API key (HTTP $status).", $results[0]->getMessage());
        $this->assertSame($status, $results[0]->getCode());
        $this->assertWaveSizes([1]);
        $this->assertSame([], $this->clock->sleeps);
    }

    public function testOtherBadRequestIsAFinalRequestError(): void
    {
        $this->transport->queue = [$this->reply(400, 'bad-request')];

        $result = $this->client()->evaluateMany([$this->request()])[0];

        $this->assertInstanceOf(RequestException::class, $result);
        $this->assertNotInstanceOf(AuthenticationException::class, $result);
        $this->assertSame(400, $result->getCode());
        $this->assertNull($this->provider()->classifyError($this->reply(400, 'bad-request')));
        $this->assertWaveSizes([1]);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function finishReasonProvider(): array
    {
        return [
            'safety' => ['safety', 'Invalid answer: the reply ended with finishReason "SAFETY".'],
            'max tokens' => ['max-tokens', 'Invalid answer: the reply ended with finishReason "MAX_TOKENS".'],
            'blocked prompt' => [
                'blocked',
                'Invalid answer: the prompt was blocked with blockReason "PROHIBITED_CONTENT".',
            ],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('finishReasonProvider')]
    public function testUnfinishedReplyIsRepairedOnceThenFinal(string $fixture, string $message): void
    {
        $this->transport->queue = [$this->reply(200, $fixture), $this->reply(200, $fixture)];

        $result = $this->client()->evaluateMany([$this->request()])[0];

        $this->assertInstanceOf(InvalidAnswerException::class, $result);
        $this->assertSame($message, $result->getMessage());
        $this->assertWaveSizes([1, 1]);
        $this->assertStringContainsString(
            'Your previous reply was rejected: ' . $message,
            $this->sentBodies()[1]['contents'][0]['parts'][0]['text']
        );
        $this->assertSame([], $this->saved);
    }

    /**
     * @return array<string, array{array, string}>
     */
    public static function invalidCandidateProvider(): array
    {
        $parts = ['parts' => [['text' => '{}']]];

        return [
            'no finish reason' => [
                ['candidates' => [['content' => $parts]]],
                'Invalid answer: the reply ended with finishReason "none".',
            ],
            'only thought parts' => [
                ['candidates' => [[
                    'content' => ['parts' => [['text' => '{}', 'thought' => true]]],
                    'finishReason' => 'STOP',
                ]]],
                'Invalid answer: the reply is empty.',
            ],
            'no parts' => [
                ['candidates' => [['content' => ['role' => 'model'], 'finishReason' => 'STOP']]],
                'Invalid answer: the reply is empty.',
            ],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidCandidateProvider')]
    public function testUnusableCandidateIsAnInvalidAnswer(array $body, string $error): void
    {
        $this->expectException(InvalidAnswerException::class);
        $this->expectExceptionMessage($error);
        $this->provider()->parseResponse((string)json_encode($body), $this->request(), 'm', false);
    }

    public function testBodyWithoutCandidatesIsMalformedNotInvalid(): void
    {
        try {
            $this->provider()->parseResponse('{"usageMetadata":{}}', $this->request(), 'm', false);
            $this->fail('No exception thrown.');
        } catch (TransportException $e) {
            $this->assertNotInstanceOf(InvalidAnswerException::class, $e);
            $this->assertSame('Gemini API returned a malformed response: no candidates', $e->getMessage());
        }
    }

    public function testModelFallsBackToTheRequestedOne(): void
    {
        $body = (string)json_encode([
            'candidates' => [[
                'content' => ['parts' => [['text' => '{"q1":{"answer":null,"confidence":0.2},'
                    . '"q2":{"choice":"cotton","confidence":1},"q3":{"score":0,"confidence":0.5}}']]],
                'finishReason' => 'STOP',
            ]],
        ]);

        $response = $this->provider()->parseResponse($body, $this->request(), 'requested', false);

        $this->assertSame('requested', $response->getModel());
        $this->assertSame(0, $response->getInputTokens());
        $this->assertSame(0.5, $response->getAnswer('red:rev')->getProbability());
    }

    public function testUsageCountsThoughtsAsOutput(): void
    {
        $this->assertSame(['input' => 412, 'output' => 38], $this->provider()->usage($this->fixture('success')));
        $this->assertSame(['input' => 400, 'output' => 96], $this->provider()->usage($this->fixture('thought')));
        $this->assertSame(['input' => 400, 'output' => 0], $this->provider()->usage($this->fixture('safety')));
        $this->assertSame(['input' => 0, 'output' => 0], $this->provider()->usage($this->fixture('auth-error')));
        $this->assertSame(
            ['input' => 5, 'output' => 2],
            $this->provider()->usage(
                '{"usageMetadata":{"promptTokenCount":5,"candidatesTokenCount":2,"thoughtsTokenCount":"x"}}'
            )
        );
    }

    public function testListModels(): void
    {
        $this->values[Config::XML_PATH_TIMEOUT] = '60';
        $this->transport->queue = [$this->reply(200, 'models')];

        $this->assertSame(
            ['gemini-3.5-flash-lite', 'gemini-3.5-flash', 'gemini-3.5-pro'],
            $this->provider()->listModels()
        );

        [$http] = $this->transport->sent();
        $this->assertSame('GET', $http->getMethod());
        $this->assertSame('https://generativelanguage.googleapis.com/v1beta/models?pageSize=1000', $http->getUrl());
        $this->assertNull($http->getBody());
        $this->assertSame(10, $http->getTimeout());
        $this->assertSame(['x-goog-api-key' => self::KEY, 'Accept' => 'application/json'], $http->getHeaders());
    }

    /**
     * @return array<string, array{HttpResponse, class-string, string}>
     */
    public static function listModelsErrorProvider(): array
    {
        $dir = dirname(__DIR__, 3) . '/Fixtures/Provider/gemini/';

        return [
            'invalid key' => [
                new HttpResponse(400, [], (string)file_get_contents($dir . 'auth-error.json')),
                AuthenticationException::class,
                'Gemini API rejected the API key (HTTP 400).',
            ],
            'permission denied' => [
                new HttpResponse(403, [], (string)file_get_contents($dir . 'permission-denied.json')),
                AuthenticationException::class,
                'Gemini API rejected the API key (HTTP 403).',
            ],
            'not found' => [
                new HttpResponse(404, [], 'Not Found'),
                RequestException::class,
                'Gemini API rejected the request (HTTP 404): Not Found',
            ],
            'transport' => [
                new HttpResponse(0, [], '', 6, 'Could not resolve host'),
                TransportException::class,
                'Gemini API request failed: Could not resolve host',
            ],
            'no list' => [
                new HttpResponse(200, [], '{"nextPageToken":""}'),
                TransportException::class,
                'Gemini API returned a malformed response: no models list',
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
