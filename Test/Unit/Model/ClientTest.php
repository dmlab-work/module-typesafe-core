<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypeSafeCore\Test\Unit\Model;

use DmLab\TypeSafeCore\Api\ClientInterface;
use DmLab\TypeSafeCore\Api\Data\Question\NoulQuestion;
use DmLab\TypeSafeCore\Api\Data\Request;
use DmLab\TypeSafeCore\Api\Data\Response;
use DmLab\TypeSafeCore\Api\ProviderInterface;
use DmLab\TypeSafeCore\Exception\AuthenticationException;
use DmLab\TypeSafeCore\Exception\BudgetExceededException;
use DmLab\TypeSafeCore\Exception\CircuitOpenException;
use DmLab\TypeSafeCore\Exception\InvalidAnswerException;
use DmLab\TypeSafeCore\Exception\NotConfiguredException;
use DmLab\TypeSafeCore\Exception\RateLimitException;
use DmLab\TypeSafeCore\Exception\RequestException;
use DmLab\TypeSafeCore\Exception\TransportException;
use DmLab\TypeSafeCore\Exception\TypeSafeException;
use DmLab\TypeSafeCore\Model\Cache\ResponseCache;
use DmLab\TypeSafeCore\Model\Client;
use DmLab\TypeSafeCore\Model\Config;
use DmLab\TypeSafeCore\Model\Http\Backoff;
use DmLab\TypeSafeCore\Model\Http\CircuitBreaker;
use DmLab\TypeSafeCore\Model\Http\HttpRequest;
use DmLab\TypeSafeCore\Model\Http\HttpResponse;
use DmLab\TypeSafeCore\Model\Http\RateLimitGuard;
use DmLab\TypeSafeCore\Model\Json\CanonicalJson;
use DmLab\TypeSafeCore\Model\Provider\ProviderPool;
use DmLab\TypeSafeCore\Model\Provider\ProviderSettings;
use DmLab\TypeSafeCore\Model\Provider\TypeSafe\ResponseParser;
use DmLab\TypeSafeCore\Model\Provider\TypeSafe\TypeSafeProvider;
use DmLab\TypeSafeCore\Model\RequestEncoder;
use DmLab\TypeSafeCore\Model\Usage\UsageLedger;
use DmLab\TypeSafeCore\Test\Unit\Fake\ArrayCache;
use DmLab\TypeSafeCore\Test\Unit\Fake\FakeClock;
use DmLab\TypeSafeCore\Test\Unit\Fake\FakeLlmProvider;
use DmLab\TypeSafeCore\Test\Unit\Fake\FakeTransport;
use Magento\Framework\App\Config\ScopeConfigInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ClientTest extends TestCase
{
    private const API_KEY = 'ts-secret-key';
    private const BASE_URL = 'https://api.example.test';

    /** @var bool */
    private bool $enabled = true;

    /** @var string */
    private string $apiKey = self::API_KEY;

    /** @var string|null `dmlab_typesafe/general/provider` */
    private ?string $providerCode = null;

    /** @var int */
    private int $concurrency = 4;

    /** @var int */
    private int $maxAttempts = 4;

    /** @var array<int, int|null> remaining budget per check; the last value repeats */
    private array $budgets = [null];

    /** @var \Throwable|null thrown by the budget check */
    private ?\Throwable $budgetFailure = null;

    /** @var array<string, string> cache key => body */
    private array $cached = [];

    /** @var array<int, array> */
    private array $saved = [];

    /** @var array<int, array{string, int, bool, int}> consumer, input tokens, cache hit, output tokens */
    private array $ledgerRecords = [];

    /** @var array<int, array{string, string, array}> level, message, context */
    private array $logs = [];

    /** @var Config */
    private Config $config;

    /** @var RequestEncoder */
    private RequestEncoder $encoder;

    /** @var ProviderSettings */
    private ProviderSettings $settings;

    /** @var TypeSafeProvider */
    private TypeSafeProvider $provider;

    /** @var ArrayCache */
    private ArrayCache $circuitCache;

    /** @var FakeTransport */
    private FakeTransport $transport;

    /** @var FakeClock */
    private FakeClock $clock;

    protected function setUp(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('isEnabled')->willReturnCallback(fn () => $this->enabled);
        $config->method('getModel')->willReturn('jev-1.13.0');
        $config->method('getConcurrency')->willReturnCallback(fn () => $this->concurrency);
        $config->method('getMaxAttempts')->willReturnCallback(fn () => $this->maxAttempts);
        $config->method('getMonthlyTokenBudget')->willReturn(1000);
        $this->config = $config;

        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(fn (string $path) => match ($path) {
            Config::XML_PATH_API_KEY => $this->apiKey,
            Config::XML_PATH_BASE_URL => self::BASE_URL,
            ProviderSettings::XML_PATH_PROVIDER => $this->providerCode,
            default => null,
        });
        $this->settings = new ProviderSettings($scopeConfig);

        $this->encoder = new RequestEncoder($config, new CanonicalJson());
        $this->circuitCache = new ArrayCache();
        $this->transport = new FakeTransport();
        $this->clock = new FakeClock();
        $this->provider = new TypeSafeProvider($this->settings, new ResponseParser(), new Backoff(), $this->transport);
    }

    private function client(?ProviderInterface $provider = null): Client
    {
        $cache = $this->createStub(ResponseCache::class);
        $cache->method('get')->willReturnCallback(fn (string $key) => $this->cached[$key] ?? null);
        $cache->method('save')->willReturnCallback(function (...$args): void {
            $this->saved[] = $args;
        });

        $ledger = $this->createStub(UsageLedger::class);
        $ledger->method('record')->willReturnCallback(
            function (string $consumer, int $input, bool $hit, int $output = 0): void {
                $this->ledgerRecords[] = [$consumer, $input, $hit, $output];
            }
        );
        $ledger->method('getRemainingBudget')->willReturnCallback(function (): ?int {
            if ($this->budgetFailure !== null) {
                throw $this->budgetFailure;
            }

            return count($this->budgets) > 1 ? array_shift($this->budgets) : $this->budgets[0];
        });

        $logger = $this->createStub(LoggerInterface::class);
        foreach (['warning', 'error'] as $level) {
            $logger->method($level)->willReturnCallback(function ($message, array $context = []) use ($level): void {
                $this->logs[] = [$level, (string)$message, $context];
            });
        }

        $provider ??= $this->provider;

        return new Client(
            $this->config,
            $this->encoder,
            new ProviderPool($this->settings, [$provider->getCode() => $provider]),
            $cache,
            $ledger,
            new CircuitBreaker($this->circuitCache, $logger),
            new RateLimitGuard($this->circuitCache, $this->clock, $logger),
            new Backoff(),
            $this->clock,
            $this->transport,
            $logger
        );
    }

    private function request(string $state = 'A red cotton shirt', ?string $model = null): Request
    {
        return new Request($state, ['q' => new NoulQuestion('Is it red?')], 'catalog_enrich', $model);
    }

    private function okBody(int $tokens = 42, string $model = 'jev-1.13.0'): string
    {
        return (string)json_encode([
            'model' => $model,
            'answers' => ['q' => ['type' => 'noul', 'noul' => 0.9]],
            'usage' => ['input_tokens' => $tokens, 'output_tokens' => 1],
        ]);
    }

    private function ok(int $tokens = 42): HttpResponse
    {
        return new HttpResponse(200, ['Content-Type' => 'application/json'], $this->okBody($tokens));
    }

    private function key(Request $request, string $model): string
    {
        return $this->encoder->cacheKey($request, $model, $this->provider);
    }

    private function cache(Request $request): void
    {
        $this->cached[$this->key($request, $this->encoder->resolveModel($request))] = $this->okBody(77);
    }

    /**
     * @param int[] $sizes
     */
    private function assertWaveSizes(array $sizes): void
    {
        $this->assertSame($sizes, array_map('count', $this->transport->calls));
    }

    public function testImplementsClientInterface(): void
    {
        $this->assertInstanceOf(ClientInterface::class, $this->client());
    }

    public function testEmptyBatchReturnsEmpty(): void
    {
        $this->assertSame([], $this->client()->evaluateMany([]));
        $this->assertSame([], $this->transport->calls);
    }

    public function testDisabledGivesNotConfiguredForEveryKey(): void
    {
        $this->enabled = false;
        $this->cache($this->request());

        $results = $this->client()->evaluateMany(['a' => $this->request(), 'b' => $this->request('other')]);

        $this->assertSame(['a', 'b'], array_keys($results));
        foreach ($results as $result) {
            $this->assertInstanceOf(NotConfiguredException::class, $result);
            $this->assertSame('TypeSafe is disabled.', $result->getMessage());
        }
        $this->assertSame([], $this->transport->calls);
        $this->assertSame([], $this->ledgerRecords);
    }

    public function testMissingApiKeyGivesNotConfiguredForEveryKey(): void
    {
        $this->apiKey = '';

        $results = $this->client()->evaluateMany([$this->request(), $this->request('other')]);

        $this->assertCount(2, $results);
        foreach ($results as $result) {
            $this->assertInstanceOf(NotConfiguredException::class, $result);
            $this->assertSame('TypeSafe API key is not set.', $result->getMessage());
        }
        $this->assertSame([], $this->transport->calls);
    }

    public function testUnknownActiveProviderGivesNotConfiguredForEveryKey(): void
    {
        $this->providerCode = 'nope';

        $results = $this->client()->evaluateMany(['a' => $this->request(), 'b' => $this->request('other')]);

        foreach ($results as $result) {
            $this->assertInstanceOf(NotConfiguredException::class, $result);
            $this->assertSame('Unknown AI provider "nope".', $result->getMessage());
        }
        $this->assertNotSame($results['a'], $results['b']);
        $this->assertSame([], $this->transport->calls);
    }

    public function testTypeSafeCacheKeyEqualsThePreProviderKey(): void
    {
        // sha256 of {"model":"jev-1.13.0","questions":{"q":{…}},"state":"A red cotton shirt"}, computed on 0.0.2.
        $legacyKey = '1ca4a8153e5d155f74db3aa9aa03b707666af8256a170b029d403d4207d7e0fb';
        $this->transport->queue = [$this->ok()];

        $this->client()->evaluate($this->request());
        $this->assertSame($legacyKey, $this->saved[0][0]);

        $this->cached[$legacyKey] = $this->okBody(77);
        $this->assertTrue($this->client()->evaluate($this->request())->isCached());
        $this->assertWaveSizes([1]);
    }

    public function testProviderClassificationAndLabelAreUsed(): void
    {
        $provider = new class (
            $this->settings,
            new ResponseParser(),
            new Backoff(),
            $this->transport
        ) extends TypeSafeProvider {
            public function getLabel(): string
            {
                return 'Other AI';
            }

            public function classifyError(HttpResponse $response): ?TypeSafeException
            {
                return $response->getStatus() === 402
                    ? new RequestException(__('Quota gone.'), null, 402)
                    : null;
            }
        };
        $this->transport->queue = [new HttpResponse(402, [], ''), new HttpResponse(401, [], '')];

        $results = $this->client($provider)->evaluateMany([$this->request('a'), $this->request('b')]);

        $this->assertSame('Quota gone.', $results[0]->getMessage());
        $this->assertSame('Other AI rejected the API key (HTTP 401).', $results[1]->getMessage());
    }

    public function testCacheHitSkipsHttpAndRecordsHit(): void
    {
        $request = $this->request();
        $this->cache($request);

        $response = $this->client()->evaluate($request);

        $this->assertTrue($response->isCached());
        $this->assertSame(0, $response->getInputTokens());
        $this->assertSame([], $this->transport->calls);
        $this->assertSame([['catalog_enrich', 0, true, 0]], $this->ledgerRecords);
        $this->assertSame([], $this->saved);
    }

    public function testUnreadableCachedBodyIsAMiss(): void
    {
        $request = $this->request();
        $this->cached[$this->key($request, 'jev-1.13.0')] = '{"broken"';
        $this->transport->queue = [$this->ok()];

        $response = $this->client()->evaluate($request);

        $this->assertFalse($response->isCached());
        $this->assertWaveSizes([1]);
        $this->assertCount(1, $this->saved);
        $this->assertSame([['catalog_enrich', 42, false, 0]], $this->ledgerRecords);
    }

    public function testMissSendsOnceThenCachesAndRecordsTokens(): void
    {
        $request = $this->request();
        $this->transport->queue = [$this->ok(42)];

        $response = $this->client()->evaluate($request);

        $this->assertFalse($response->isCached());
        $this->assertSame(42, $response->getInputTokens());
        $this->assertSame(0.9, $response->getAnswer('q')->getProbability());
        $this->assertWaveSizes([1]);
        $this->assertSame(
            [[$this->key($request, 'jev-1.13.0'), 'catalog_enrich', 'jev-1.13.0', $this->okBody(42), 42]],
            $this->saved
        );
        $this->assertSame([['catalog_enrich', 42, false, 0]], $this->ledgerRecords);
    }

    public function testExhaustedBudgetSendsNothingButServesCacheHits(): void
    {
        $this->budgets = [0];
        $hit = $this->request('cached');
        $this->cache($hit);

        $results = $this->client()->evaluateMany(['hit' => $hit, 'miss' => $this->request('fresh')]);

        $this->assertInstanceOf(Response::class, $results['hit']);
        $this->assertTrue($results['hit']->isCached());
        $this->assertInstanceOf(BudgetExceededException::class, $results['miss']);
        $this->assertSame('TypeSafe monthly token budget (1000) is used up.', $results['miss']->getMessage());
        $this->assertSame([], $this->transport->calls);
    }

    public function testOpenCircuitSendsNothingButServesCacheHits(): void
    {
        $this->circuitCache->data[CircuitBreaker::OPEN_KEY] = '1';
        $hit = $this->request('cached');
        $this->cache($hit);

        $results = $this->client()->evaluateMany(['hit' => $hit, 'miss' => $this->request('fresh')]);

        $this->assertInstanceOf(Response::class, $results['hit']);
        $this->assertInstanceOf(CircuitOpenException::class, $results['miss']);
        $this->assertSame(
            'TypeSafe calls are paused for up to 60 s after repeated failures.',
            $results['miss']->getMessage()
        );
        $this->assertSame([], $this->transport->calls);
    }

    public function testMixedBatchGivesPerKeyResultsInInputOrder(): void
    {
        $hit = $this->request('cached');
        $this->cache($hit);
        $this->transport->queue = [
            $this->ok(),
            new HttpResponse(400, [], '{"message":"state is too long"}'),
        ];

        $results = $this->client()->evaluateMany(
            ['ok' => $this->request('fresh'), 'hit' => $hit, 7 => $this->request('bad')]
        );

        $this->assertSame(['ok', 'hit', 7], array_keys($results));
        $this->assertInstanceOf(Response::class, $results['ok']);
        $this->assertFalse($results['ok']->isCached());
        $this->assertTrue($results['hit']->isCached());
        $this->assertInstanceOf(RequestException::class, $results[7]);
        $this->assertSame(400, $results[7]->getCode());
        $this->assertSame('TypeSafe rejected the request (HTTP 400): state is too long', $results[7]->getMessage());
        $this->assertWaveSizes([2]);
    }

    public function testMissesGoOutInWavesOfConcurrency(): void
    {
        $this->transport->handler = fn () => $this->ok();
        $requests = [];
        for ($i = 0; $i < 10; $i++) {
            $requests[] = $this->request('state ' . $i);
        }

        $results = $this->client()->evaluateMany($requests);

        $this->assertWaveSizes([4, 4, 2]);
        $this->assertSame(range(0, 9), array_keys($results));
        $this->assertContainsOnlyInstancesOf(Response::class, $results);
    }

    public function testBudgetIsRecheckedBeforeEveryWave(): void
    {
        $this->budgets = [100, 0];
        $this->transport->handler = fn () => $this->ok();
        $requests = [];
        for ($i = 0; $i < 10; $i++) {
            $requests[] = $this->request('state ' . $i);
        }

        $results = $this->client()->evaluateMany($requests);

        $this->assertWaveSizes([4]);
        $this->assertContainsOnlyInstancesOf(Response::class, array_slice($results, 0, 4));
        $this->assertContainsOnlyInstancesOf(BudgetExceededException::class, array_slice($results, 4));
    }

    public function testEncodeErrorIsPerKey(): void
    {
        $this->transport->queue = [$this->ok()];

        $results = $this->client()->evaluateMany([
            'bad' => new Request(['weight' => NAN], ['q' => new NoulQuestion('Is it red?')], 'catalog_enrich'),
            'good' => $this->request(),
        ]);

        $this->assertInstanceOf(RequestException::class, $results['bad']);
        $this->assertStringStartsWith('TypeSafe request could not be encoded:', $results['bad']->getMessage());
        $this->assertInstanceOf(Response::class, $results['good']);
        $this->assertWaveSizes([1]);
    }

    public function testUnexpectedThrowableFailsOnlyKeysWithoutAResult(): void
    {
        $this->budgetFailure = new \RuntimeException('database gone');
        $hit = $this->request('cached');
        $this->cache($hit);

        $results = $this->client()->evaluateMany(
            ['hit' => $hit, 'a' => $this->request('a'), 'b' => $this->request('b')]
        );

        $this->assertSame(['hit', 'a', 'b'], array_keys($results));
        $this->assertInstanceOf(Response::class, $results['hit']);
        foreach (['a', 'b'] as $key) {
            $this->assertInstanceOf(TransportException::class, $results[$key]);
            $this->assertSame('TypeSafe client error: database gone', $results[$key]->getMessage());
            $this->assertSame($this->budgetFailure, $results[$key]->getPrevious());
        }
    }

    public function testTransportErrorObjectIsWrapped(): void
    {
        $this->transport->handler = static fn () => throw new \Error('engine failure');

        $result = $this->client()->evaluateMany(['a' => $this->request()])['a'];

        $this->assertInstanceOf(TransportException::class, $result);
        $this->assertSame('TypeSafe client error: engine failure', $result->getMessage());
        $this->assertNull($result->getPrevious());
    }

    public function testEvaluateReturnsTheResponse(): void
    {
        $this->transport->queue = [$this->ok()];

        $this->assertInstanceOf(Response::class, $this->client()->evaluate($this->request()));
    }

    public function testEvaluateRethrowsTheResultException(): void
    {
        $this->enabled = false;

        $this->expectException(NotConfiguredException::class);
        $this->expectExceptionMessage('TypeSafe is disabled.');

        $this->client()->evaluate($this->request());
    }

    public function testRequestModelOverrideIsUsedInBodyAndKey(): void
    {
        $request = $this->request('state', 'jev-1.14.0');
        $this->transport->queue = [$this->ok()];

        $this->client()->evaluate($request);

        $body = json_decode((string)$this->transport->sent()[0]->getBody(), true);
        $this->assertSame('jev-1.14.0', $body['model']);
        $this->assertSame($this->key($request, 'jev-1.14.0'), $this->saved[0][0]);
        $this->assertNotSame($this->key($request, 'jev-1.13.0'), $this->saved[0][0]);
    }

    public function testHttpRequestShapeIsExact(): void
    {
        $request = $this->request();
        $this->transport->queue = [$this->ok()];

        $this->client()->evaluate($request);

        $sent = $this->transport->sent()[0];
        $this->assertSame('POST', $sent->getMethod());
        $this->assertSame(self::BASE_URL . '/v1/systemone', $sent->getUrl());
        $this->assertSame(
            [
                'Authorization' => 'Bearer ' . self::API_KEY,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'Expect' => '',
            ],
            $sent->getHeaders()
        );
        $this->assertSame(
            '{"model":"jev-1.13.0","state":"A red cotton shirt",'
            . '"questions":{"q":{"type":"noul","instructions":"Is it red?"}}}',
            $sent->getBody()
        );
        $this->assertSame(20, $sent->getTimeout());
    }

    public function testMalformedSuccessBodyIsFinalAndNotCached(): void
    {
        $this->transport->queue = [new HttpResponse(200, [], '{"model":"jev-1.13.0"}')];

        $result = $this->client()->evaluateMany([$this->request()])[0];

        $this->assertInstanceOf(TransportException::class, $result);
        $this->assertStringStartsWith('TypeSafe returned a malformed response:', $result->getMessage());
        $this->assertSame([], $this->saved);
        $this->assertSame([], $this->ledgerRecords);
    }

    public function testMalformedSuccessBodyStillRecordsBilledTokens(): void
    {
        $this->transport->queue = [new HttpResponse(200, [], '{"answers":{},"usage":{"input_tokens":90}}')];

        $result = $this->client()->evaluateMany([$this->request()])[0];

        $this->assertInstanceOf(TransportException::class, $result);
        $this->assertSame([], $this->saved);
        $this->assertSame([['catalog_enrich', 90, false, 0]], $this->ledgerRecords);
    }

    public function testFinalErrorMapping(): void
    {
        $this->maxAttempts = 1;
        $this->transport->queue = [
            new HttpResponse(401),
            new HttpResponse(403),
            new HttpResponse(429, ['Retry-After' => '7']),
            new HttpResponse(429),
            new HttpResponse(503),
            new HttpResponse(0, [], '', 6, 'Could not resolve host'),
            new HttpResponse(404, [], str_repeat('x', 300)),
            new HttpResponse(422, [], '{"error":{"message":"unknown question type"}}'),
        ];
        $requests = [];
        for ($i = 0; $i < 8; $i++) {
            $requests[] = $this->request('state ' . $i);
        }
        $this->concurrency = 8;

        $r = $this->client()->evaluateMany($requests);

        $this->assertInstanceOf(AuthenticationException::class, $r[0]);
        $this->assertSame(401, $r[0]->getCode());
        $this->assertSame('TypeSafe rejected the API key (HTTP 401).', $r[0]->getMessage());
        $this->assertInstanceOf(AuthenticationException::class, $r[1]);
        $this->assertSame(403, $r[1]->getCode());
        $this->assertInstanceOf(RateLimitException::class, $r[2]);
        $this->assertSame(7, $r[2]->getRetryAfter());
        $this->assertSame(429, $r[2]->getCode());
        $this->assertSame('TypeSafe rate limit reached; retry after 7 s.', $r[2]->getMessage());
        $this->assertInstanceOf(RateLimitException::class, $r[3]);
        $this->assertNull($r[3]->getRetryAfter());
        $this->assertSame('TypeSafe rate limit reached.', $r[3]->getMessage());
        $this->assertInstanceOf(TransportException::class, $r[4]);
        $this->assertSame(503, $r[4]->getCode());
        $this->assertSame('TypeSafe answered HTTP 503.', $r[4]->getMessage());
        $this->assertInstanceOf(TransportException::class, $r[5]);
        $this->assertSame('TypeSafe request failed: Could not resolve host', $r[5]->getMessage());
        $this->assertInstanceOf(RequestException::class, $r[6]);
        $this->assertSame(404, $r[6]->getCode());
        $this->assertSame('TypeSafe rejected the request (HTTP 404): ' . str_repeat('x', 200), $r[6]->getMessage());
        $this->assertSame('TypeSafe rejected the request (HTTP 422): unknown question type', $r[7]->getMessage());
        $this->assertSame('1', $this->circuitCache->data[CircuitBreaker::FAILURES_KEY]);
    }

    /**
     * @return array<int, array{string, string, array}>
     */
    private function attemptLogs(): array
    {
        return array_values(array_filter(
            $this->logs,
            static fn (array $log) => str_starts_with($log[1], 'TypeSafe request failed (attempt')
        ));
    }

    public function testRateLimitHonoursRetryAfterThenSucceeds(): void
    {
        $this->transport->queue = [new HttpResponse(429, ['Retry-After' => '2']), $this->ok()];

        $response = $this->client()->evaluate($this->request());

        $this->assertFalse($response->isCached());
        $this->assertWaveSizes([1, 1]);
        $this->assertSame([2.0], $this->clock->sleeps);
    }

    public function testRateLimitOnEveryAttemptGivesRateLimitException(): void
    {
        $this->transport->handler = static fn () => new HttpResponse(429, ['retry-after' => '1']);

        $result = $this->client()->evaluateMany([$this->request()])[0];

        $this->assertInstanceOf(RateLimitException::class, $result);
        $this->assertSame(1, $result->getRetryAfter());
        $this->assertWaveSizes([1, 1, 1, 1]);
        $this->assertSame([1.0, 1.0, 1.0], $this->clock->sleeps);
    }

    public function testRateLimitWithoutHeaderUsesBackoff(): void
    {
        $this->transport->queue = [new HttpResponse(429), $this->ok()];

        $this->client()->evaluate($this->request());

        $this->assertCount(1, $this->clock->sleeps);
        $this->assertGreaterThanOrEqual(0.25, $this->clock->sleeps[0]);
        $this->assertLessThanOrEqual(0.5, $this->clock->sleeps[0]);
    }

    public function testRetryAfterOfExactlySixtySecondsIsRetried(): void
    {
        $this->transport->queue = [new HttpResponse(429, ['Retry-After' => '60']), $this->ok()];

        $response = $this->client()->evaluate($this->request());

        $this->assertFalse($response->isCached());
        $this->assertSame([60.0], $this->clock->sleeps);
    }

    public function testRetryAfterAboveSixtySecondsIsNotRetried(): void
    {
        $this->transport->queue = [new HttpResponse(429, ['Retry-After' => '120'])];

        $result = $this->client()->evaluateMany([$this->request()])[0];

        $this->assertInstanceOf(RateLimitException::class, $result);
        $this->assertSame(120, $result->getRetryAfter());
        $this->assertWaveSizes([1]);
        $this->assertSame([], $this->clock->sleeps);
    }

    public function testFinalRateLimitPausesLaterCallsForRetryAfter(): void
    {
        $this->transport->queue = [new HttpResponse(429, ['Retry-After' => '120'])];

        $this->client()->evaluateMany([$this->request('a')]);
        $this->assertSame(120, $this->circuitCache->lifetimes[RateLimitGuard::UNTIL_KEY]);

        $this->clock->time += 20;
        $hit = $this->request('cached');
        $this->cache($hit);
        $results = $this->client()->evaluateMany(['hit' => $hit, 'miss' => $this->request('b')]);

        $this->assertWaveSizes([1]);
        $this->assertInstanceOf(Response::class, $results['hit']);
        $this->assertTrue($results['hit']->isCached());
        $this->assertInstanceOf(RateLimitException::class, $results['miss']);
        $this->assertSame(100, $results['miss']->getRetryAfter());
        $this->assertSame(429, $results['miss']->getCode());
        $this->assertSame('TypeSafe rate limit reached; calls are paused for 100 s.', $results['miss']->getMessage());
    }

    public function testRateLimitAfterAllAttemptsWithoutHeaderPausesForTheDefault(): void
    {
        $this->maxAttempts = 2;
        $this->transport->handler = static fn () => new HttpResponse(429);

        $this->client()->evaluateMany([$this->request('a')]);
        $this->transport->calls = [];
        $result = $this->client()->evaluateMany([$this->request('b')])[0];

        $this->assertSame([], $this->transport->calls);
        $this->assertInstanceOf(RateLimitException::class, $result);
        $this->assertSame(RateLimitGuard::DEFAULT_SECONDS, $result->getRetryAfter());
    }

    public function testCallsResumeWhenThePauseEnds(): void
    {
        $this->transport->queue = [new HttpResponse(429, ['Retry-After' => '90']), $this->ok()];
        $this->client()->evaluateMany([$this->request('a')]);

        $this->clock->time += 90;
        $result = $this->client()->evaluateMany([$this->request('b')])[0];

        $this->assertInstanceOf(Response::class, $result);
        $this->assertWaveSizes([1, 1]);
    }

    public function testRetriedRateLimitThatSucceedsDoesNotPause(): void
    {
        $this->transport->queue = [new HttpResponse(429, ['Retry-After' => '2']), $this->ok()];

        $this->client()->evaluate($this->request());

        $this->assertArrayNotHasKey(RateLimitGuard::UNTIL_KEY, $this->circuitCache->data);
    }

    public function testServerErrorIsRetriedWithBackoff(): void
    {
        $this->transport->queue = [new HttpResponse(503), $this->ok()];

        $response = $this->client()->evaluate($this->request());

        $this->assertInstanceOf(Response::class, $response);
        $this->assertWaveSizes([1, 1]);
        $this->assertCount(1, $this->clock->sleeps);
        $this->assertGreaterThanOrEqual(0.25, $this->clock->sleeps[0]);
        $this->assertLessThanOrEqual(0.5, $this->clock->sleeps[0]);
    }

    /**
     * @return array<string, array{int}>
     */
    public static function retryableStatusProvider(): array
    {
        return ['408' => [408], '500' => [500], '529' => [529]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('retryableStatusProvider')]
    public function testRetryableStatusesAreRetried(int $status): void
    {
        $this->transport->queue = [new HttpResponse($status), $this->ok()];

        $this->assertInstanceOf(Response::class, $this->client()->evaluate($this->request()));
        $this->assertWaveSizes([1, 1]);
    }

    public function testTransportErrorOnEveryAttemptGivesTransportException(): void
    {
        $this->transport->handler = static fn () => new HttpResponse(0, [], '', 7, 'Failed to connect');

        $result = $this->client()->evaluateMany([$this->request()])[0];

        $this->assertInstanceOf(TransportException::class, $result);
        $this->assertSame('TypeSafe request failed: Failed to connect', $result->getMessage());
        $this->assertWaveSizes([1, 1, 1, 1]);
        $this->assertCount(3, $this->clock->sleeps);
        foreach ($this->clock->sleeps as $attempt => $sleep) {
            $cap = 0.5 * 2 ** $attempt;
            $this->assertGreaterThanOrEqual($cap / 2, $sleep);
            $this->assertLessThanOrEqual($cap, $sleep);
        }
        $this->assertSame('4', $this->circuitCache->data[CircuitBreaker::FAILURES_KEY]);
    }

    /**
     * @return array<string, array{int}>
     */
    public static function clientErrorProvider(): array
    {
        return ['400' => [400], '404' => [404], '413' => [413], '422' => [422]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('clientErrorProvider')]
    public function testClientErrorsAreFinal(int $status): void
    {
        $this->transport->queue = [new HttpResponse($status, [], '{"detail":"bad input"}')];

        $result = $this->client()->evaluateMany([$this->request()])[0];

        $this->assertInstanceOf(RequestException::class, $result);
        $this->assertSame($status, $result->getCode());
        $this->assertSame(
            sprintf('TypeSafe rejected the request (HTTP %d): bad input', $status),
            $result->getMessage()
        );
        $this->assertWaveSizes([1]);
        $this->assertSame([], $this->clock->sleeps);
    }

    /**
     * @return array<string, array{int}>
     */
    public static function authErrorProvider(): array
    {
        return ['401' => [401], '403' => [403]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('authErrorProvider')]
    public function testAuthErrorsAreFinalAndNotCountedByTheCircuit(int $status): void
    {
        $this->circuitCache->data[CircuitBreaker::FAILURES_KEY] = '4';
        $this->transport->queue = [new HttpResponse($status)];

        $result = $this->client()->evaluateMany([$this->request()])[0];

        $this->assertInstanceOf(AuthenticationException::class, $result);
        $this->assertSame($status, $result->getCode());
        $this->assertWaveSizes([1]);
        $this->assertSame('4', $this->circuitCache->data[CircuitBreaker::FAILURES_KEY]);
        $this->assertArrayNotHasKey(CircuitBreaker::OPEN_KEY, $this->circuitCache->data);
    }

    public function testMixedWaveRetriesOnlyTheFailedKey(): void
    {
        $this->transport->queue = [$this->ok(5), new HttpResponse(502), $this->ok(9)];

        $results = $this->client()->evaluateMany(['a' => $this->request('a'), 'b' => $this->request('b')]);

        $this->assertWaveSizes([2, 1]);
        $this->assertSame(['b'], array_keys($this->transport->calls[1]));
        $this->assertSame(5, $results['a']->getInputTokens());
        $this->assertSame(9, $results['b']->getInputTokens());
        $this->assertCount(1, $this->clock->sleeps);
    }

    public function testOneSleepPerRoundUsesTheLongestWait(): void
    {
        $this->transport->queue = [
            new HttpResponse(429, ['Retry-After' => '3']),
            new HttpResponse(429, ['Retry-After' => '5']),
            $this->ok(),
            $this->ok(),
        ];

        $this->client()->evaluateMany([$this->request('a'), $this->request('b')]);

        $this->assertSame([5.0], $this->clock->sleeps);
        $this->assertWaveSizes([2, 2]);
    }

    public function testSuccessInARoundResetsTheCircuitCounter(): void
    {
        $this->circuitCache->data[CircuitBreaker::FAILURES_KEY] = '3';
        $this->transport->queue = [$this->ok(), new HttpResponse(500), $this->ok()];

        $this->client()->evaluateMany([$this->request('a'), $this->request('b')]);

        $this->assertArrayNotHasKey(CircuitBreaker::FAILURES_KEY, $this->circuitCache->data);
    }

    public function testFiveServerErrorsAcrossCallsOpenTheCircuit(): void
    {
        $this->maxAttempts = 1;
        $this->transport->handler = static fn () => new HttpResponse(503);

        for ($i = 0; $i < 5; $i++) {
            $result = $this->client()->evaluateMany([$this->request('state ' . $i)])[0];
            $this->assertInstanceOf(TransportException::class, $result);
        }
        $this->assertWaveSizes([1, 1, 1, 1, 1]);

        $result = $this->client()->evaluateMany([$this->request('next')])[0];

        $this->assertInstanceOf(CircuitOpenException::class, $result);
        $this->assertCount(5, $this->transport->calls);
        $this->assertSame(CircuitBreaker::OPEN_SECONDS, $this->circuitCache->lifetimes[CircuitBreaker::OPEN_KEY]);
    }

    public function testCircuitOpeningMidRetryStopsTheRemainingKeys(): void
    {
        $this->circuitCache->data[CircuitBreaker::FAILURES_KEY] = '3';
        $this->transport->handler = static fn () => new HttpResponse(500);

        $results = $this->client()->evaluateMany([$this->request('a'), $this->request('b'), $this->request('c')]);

        $this->assertWaveSizes([3, 3]);
        $this->assertContainsOnlyInstancesOf(CircuitOpenException::class, $results);
    }

    public function testOneWaveOfParallelServerErrorsCountsAsOneFailureAndRetries(): void
    {
        $this->concurrency = 5;
        $this->transport->queue = array_merge(
            array_fill(0, 5, new HttpResponse(503)),
            array_map(fn () => $this->ok(), range(1, 5))
        );
        $requests = array_map(fn (int $i) => $this->request('state ' . $i), range(0, 4));

        $results = $this->client()->evaluateMany($requests);

        $this->assertWaveSizes([5, 5]);
        $this->assertContainsOnlyInstancesOf(Response::class, $results);
        $this->assertArrayNotHasKey(CircuitBreaker::OPEN_KEY, $this->circuitCache->data);
    }

    public function testParallelFailuresAreCountedOncePerRound(): void
    {
        $this->concurrency = 5;
        $this->transport->handler = static fn () => new HttpResponse(0, [], '', 28, 'Operation timed out');
        $requests = array_map(fn (int $i) => $this->request('state ' . $i), range(0, 4));

        $results = $this->client()->evaluateMany($requests);

        $this->assertWaveSizes([5, 5, 5, 5]);
        $this->assertContainsOnlyInstancesOf(TransportException::class, $results);
        $this->assertSame('4', $this->circuitCache->data[CircuitBreaker::FAILURES_KEY]);
        $this->assertArrayNotHasKey(CircuitBreaker::OPEN_KEY, $this->circuitCache->data);
    }

    public function testFiveConsecutiveFailingRoundsOpenTheCircuit(): void
    {
        $this->maxAttempts = 10;
        $this->transport->handler = static fn () => new HttpResponse(529);

        $results = $this->client()->evaluateMany([$this->request('a'), $this->request('b')]);

        $this->assertWaveSizes([2, 2, 2, 2, 2]);
        $this->assertContainsOnlyInstancesOf(CircuitOpenException::class, $results);
        $this->assertSame('1', $this->circuitCache->data[CircuitBreaker::OPEN_KEY]);
    }

    /**
     * @return array<string, array{HttpResponse, class-string, int|null}>
     */
    public static function batchStopperProvider(): array
    {
        return [
            '401' => [new HttpResponse(401), AuthenticationException::class, null],
            '403' => [new HttpResponse(403), AuthenticationException::class, null],
            '429 over the retry-after cap' => [
                new HttpResponse(429, ['Retry-After' => '120']),
                RateLimitException::class,
                120,
            ],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('batchStopperProvider')]
    public function testFinalAuthOrRateLimitStopsTheRestOfTheBatch(
        HttpResponse $stopper,
        string $class,
        ?int $retryAfter
    ): void {
        $this->concurrency = 2;
        $this->transport->queue = [$this->ok(), $stopper];
        $requests = array_map(fn (int $i) => $this->request('state ' . $i), range(0, 5));

        $results = $this->client()->evaluateMany($requests);

        $this->assertWaveSizes([2]);
        $this->assertInstanceOf(Response::class, $results[0]);
        $this->assertInstanceOf($class, $results[1]);
        foreach (range(2, 5) as $index) {
            $this->assertSame($class, $results[$index]::class);
            $this->assertNotSame($results[1], $results[$index]);
            $this->assertSame($results[1]->getMessage(), $results[$index]->getMessage());
            $this->assertSame($results[1]->getCode(), $results[$index]->getCode());
            if ($results[$index] instanceof RateLimitException) {
                $this->assertSame($retryAfter, $results[$index]->getRetryAfter());
            }
        }
    }

    public function testRateLimitAfterAllAttemptsStopsTheRestOfTheBatch(): void
    {
        $this->concurrency = 1;
        $this->maxAttempts = 2;
        $this->transport->handler = static fn () => new HttpResponse(429, ['Retry-After' => '1']);

        $results = $this->client()->evaluateMany(['a' => $this->request('a'), 'b' => $this->request('b')]);

        $this->assertWaveSizes([1, 1]);
        $this->assertInstanceOf(RateLimitException::class, $results['a']);
        $this->assertInstanceOf(RateLimitException::class, $results['b']);
        $this->assertSame(1, $results['b']->getRetryAfter());
        $this->assertSame('TypeSafe rate limit reached; retry after 1 s.', $results['b']->getMessage());
    }

    public function testFinalAuthErrorCancelsPendingRetriesButKeepsAnsweredKeys(): void
    {
        $this->transport->queue = [
            $this->ok(),
            new HttpResponse(503),
            new HttpResponse(401),
            new HttpResponse(400, [], '{"message":"bad"}'),
        ];

        $results = $this->client()->evaluateMany([
            'ok' => $this->request('ok'),
            'retry' => $this->request('retry'),
            'auth' => $this->request('auth'),
            'bad' => $this->request('bad'),
        ]);

        $this->assertWaveSizes([4]);
        $this->assertSame([], $this->clock->sleeps);
        $this->assertInstanceOf(Response::class, $results['ok']);
        $this->assertInstanceOf(AuthenticationException::class, $results['retry']);
        $this->assertSame(401, $results['retry']->getCode());
        $this->assertInstanceOf(AuthenticationException::class, $results['auth']);
        $this->assertInstanceOf(RequestException::class, $results['bad']);
    }

    public function testTransportFailureDoesNotStopTheBatch(): void
    {
        $this->concurrency = 1;
        $this->maxAttempts = 1;
        $this->transport->queue = [new HttpResponse(503), $this->ok()];

        $results = $this->client()->evaluateMany([$this->request('a'), $this->request('b')]);

        $this->assertWaveSizes([1, 1]);
        $this->assertInstanceOf(TransportException::class, $results[0]);
        $this->assertInstanceOf(Response::class, $results[1]);
    }

    public function testIdenticalRequestsInOneWaveAreSentOnce(): void
    {
        $this->transport->queue = [$this->ok(42)];

        $results = $this->client()->evaluateMany([
            'a' => $this->request(),
            'b' => $this->request(),
            'c' => new Request('A red cotton shirt', ['q' => new NoulQuestion('Is it red?')], 'other_consumer'),
        ]);

        $this->assertWaveSizes([1]);
        $this->assertSame(['a', 'b', 'c'], array_keys($results));
        $this->assertFalse($results['a']->isCached());
        $this->assertSame(42, $results['a']->getInputTokens());
        foreach (['b', 'c'] as $key) {
            $this->assertTrue($results[$key]->isCached());
            $this->assertSame(0, $results[$key]->getInputTokens());
            $this->assertSame('jev-1.13.0', $results[$key]->getModel());
            $this->assertSame(0.9, $results[$key]->getAnswer('q')->getProbability());
        }
        $this->assertSame(
            [['catalog_enrich', 42, false, 0], ['catalog_enrich', 0, true, 0], ['other_consumer', 0, true, 0]],
            $this->ledgerRecords
        );
        $this->assertCount(1, $this->saved);
    }

    public function testDuplicatesDoNotTakeWaveSlots(): void
    {
        $this->concurrency = 2;
        $this->transport->handler = fn () => $this->ok();

        $results = $this->client()->evaluateMany(
            [$this->request('a'), $this->request('b'), $this->request('c'), $this->request('a'), $this->request('c')]
        );

        $this->assertWaveSizes([2, 1]);
        $this->assertSame([0, 1, 2], array_keys($this->transport->calls[0] + $this->transport->calls[1]));
        $this->assertSame([false, false, false, true, true], array_map(
            static fn (Response $response) => $response->isCached(),
            $results
        ));
    }

    public function testDuplicateOfACacheHitIsServedFromTheCache(): void
    {
        $this->cache($this->request());

        $results = $this->client()->evaluateMany([$this->request(), $this->request()]);

        $this->assertSame([], $this->transport->calls);
        $this->assertTrue($results[0]->isCached());
        $this->assertTrue($results[1]->isCached());
        $this->assertSame([['catalog_enrich', 0, true, 0], ['catalog_enrich', 0, true, 0]], $this->ledgerRecords);
    }

    public function testErrorsFanOutToDuplicates(): void
    {
        $this->transport->queue = [new HttpResponse(422, [], '{"message":"bad question"}')];

        $results = $this->client()->evaluateMany(['a' => $this->request(), 'b' => $this->request()]);

        $this->assertWaveSizes([1]);
        $this->assertInstanceOf(RequestException::class, $results['b']);
        $this->assertNotSame($results['a'], $results['b']);
        $this->assertSame($results['a']->getMessage(), $results['b']->getMessage());
        $this->assertSame(422, $results['b']->getCode());
        $this->assertSame([], $this->ledgerRecords);
    }

    public function testRefusalFansOutToDuplicates(): void
    {
        $this->budgets = [0];

        $results = $this->client()->evaluateMany([$this->request(), $this->request()]);

        $this->assertSame([], $this->transport->calls);
        $this->assertContainsOnlyInstancesOf(BudgetExceededException::class, $results);
    }

    public function testBudgetIsRecheckedBeforeEveryRetryRound(): void
    {
        $this->budgets = [100, 0];
        $this->transport->queue = [new HttpResponse(503)];

        $result = $this->client()->evaluateMany([$this->request()])[0];

        $this->assertInstanceOf(BudgetExceededException::class, $result);
        $this->assertWaveSizes([1]);
    }

    public function testEveryFailedAttemptLogsOneWarningWithoutSecrets(): void
    {
        $state = 'Top secret product state';
        $this->transport->queue = [
            new HttpResponse(503, [], '{"message":"overloaded"}'),
            new HttpResponse(0, [], '', 28, 'Operation timed out'),
            $this->ok(),
        ];

        $this->client()->evaluate($this->request($state, 'jev-1.14.0'));

        $logs = $this->attemptLogs();
        $this->assertSame(
            [
                ['warning', 'TypeSafe request failed (attempt 1/4): HTTP 503',
                    ['consumer' => 'catalog_enrich', 'model' => 'jev-1.14.0', 'status' => 503]],
                ['warning', 'TypeSafe request failed (attempt 2/4): Operation timed out',
                    ['consumer' => 'catalog_enrich', 'model' => 'jev-1.14.0', 'status' => 0]],
            ],
            $logs
        );
        $dump = json_encode($this->logs);
        $this->assertStringNotContainsString(self::API_KEY, $dump);
        $this->assertStringNotContainsString($state, $dump);
        $this->assertStringNotContainsString('overloaded', $dump);
    }

    public function testSuccessLogsNothing(): void
    {
        $this->transport->queue = [$this->ok()];

        $this->client()->evaluate($this->request());

        $this->assertSame([], $this->logs);
    }

    public function testMalformedSuccessBodyIsNotRetried(): void
    {
        $this->transport->queue = [new HttpResponse(200, [], 'not json'), $this->ok()];

        $result = $this->client()->evaluateMany([$this->request()])[0];

        $this->assertInstanceOf(TransportException::class, $result);
        $this->assertWaveSizes([1]);
        $this->assertSame([], $this->clock->sleeps);
        $this->assertCount(1, $this->transport->queue);
    }

    public function testMalformedSuccessBodyLeavesTheCircuitCounterAlone(): void
    {
        $this->circuitCache->data[CircuitBreaker::FAILURES_KEY] = '3';
        $this->transport->queue = [new HttpResponse(200, [], 'not json')];

        $this->client()->evaluateMany([$this->request()]);

        $this->assertSame('3', $this->circuitCache->data[CircuitBreaker::FAILURES_KEY]);
    }

    private function llmClient(): Client
    {
        $this->providerCode = FakeLlmProvider::CODE;

        return $this->client(new FakeLlmProvider($this->settings, $this->transport));
    }

    private function llmReply(?string $text = null, int $input = 50, int $output = 7): HttpResponse
    {
        return new HttpResponse(
            200,
            [],
            FakeLlmProvider::reply($text ?? '{"q1":{"answer":true,"confidence":0.8}}', $input, $output)
        );
    }

    /**
     * User message of each sent request.
     *
     * @return string[]
     */
    private function sentUserMessages(): array
    {
        return array_map(
            static fn ($request) => json_decode((string)$request->getBody(), true)['user'],
            $this->transport->sent()
        );
    }

    public function testInvalidAnswerIsResentOnceWithARepairNote(): void
    {
        $request = $this->request();
        $this->transport->queue = [$this->llmReply('not json', 50, 7), $this->llmReply(null, 60, 9)];

        $response = $this->llmClient()->evaluate($request);

        $this->assertSame(0.8, $response->getAnswer('q')->getProbability());
        $this->assertSame(60, $response->getInputTokens());
        $this->assertWaveSizes([1, 1]);
        [$first, $second] = $this->sentUserMessages();
        $this->assertStringNotContainsString('previous reply was rejected', $first);
        $this->assertStringStartsWith($first . "\n\n", $second);
        $this->assertStringContainsString(
            'Your previous reply was rejected: Invalid answer: the reply is not valid JSON.',
            $second
        );
        $this->assertSame([], $this->clock->sleeps);
        $this->assertCount(1, $this->saved);
        $this->assertSame(
            $this->encoder->cacheKey($request, 'jev-1.13.0', new FakeLlmProvider($this->settings, $this->transport)),
            $this->saved[0][0]
        );
        $this->assertSame($this->llmReply(null, 60, 9)->getBody(), $this->saved[0][3]);
        $this->assertSame(
            [['catalog_enrich', 50, false, 7], ['catalog_enrich', 60, false, 9]],
            $this->ledgerRecords
        );
    }

    public function testInvalidAnswerTwiceIsFinal(): void
    {
        $this->transport->queue = [
            $this->llmReply('not json'),
            $this->llmReply('{"q1":{"answer":"yes","confidence":0.8}}'),
            $this->llmReply(),
        ];

        $result = $this->llmClient()->evaluateMany([$this->request()])[0];

        $this->assertInstanceOf(InvalidAnswerException::class, $result);
        $this->assertStringStartsWith('Invalid answer: q1.', $result->getMessage());
        $this->assertWaveSizes([1, 1]);
        $this->assertCount(1, $this->transport->queue);
        $this->assertSame([], $this->saved);
        $this->assertSame(
            [['catalog_enrich', 50, false, 7], ['catalog_enrich', 50, false, 7]],
            $this->ledgerRecords
        );
    }

    public function testInvalidAnswerIsRepairedEvenOnTheLastAttempt(): void
    {
        $this->maxAttempts = 1;
        $this->transport->queue = [$this->llmReply('not json'), $this->llmReply()];

        $this->assertInstanceOf(Response::class, $this->llmClient()->evaluate($this->request()));
        $this->assertWaveSizes([1, 1]);
    }

    public function testInvalidAnswersDoNotCountTowardsTheCircuit(): void
    {
        $this->circuitCache->data[CircuitBreaker::FAILURES_KEY] = '4';
        $this->transport->handler = fn () => $this->llmReply('not json');

        $results = $this->llmClient()->evaluateMany([$this->request('a'), $this->request('b')]);

        $this->assertContainsOnlyInstancesOf(InvalidAnswerException::class, $results);
        $this->assertSame('4', $this->circuitCache->data[CircuitBreaker::FAILURES_KEY]);
        $this->assertArrayNotHasKey(CircuitBreaker::OPEN_KEY, $this->circuitCache->data);
    }

    public function testInvalidAnswerDoesNotStopTheBatch(): void
    {
        $this->concurrency = 1;
        $this->transport->handler = fn (HttpRequest $request) => str_contains(
            (string)$request->getBody(),
            'bad product'
        ) ? $this->llmReply('[]') : $this->llmReply();

        $results = $this->llmClient()->evaluateMany(
            [$this->request('bad product'), $this->request('a'), $this->request('b')]
        );

        $this->assertInstanceOf(InvalidAnswerException::class, $results[0]);
        $this->assertInstanceOf(Response::class, $results[1]);
        $this->assertInstanceOf(Response::class, $results[2]);
        $this->assertWaveSizes([1, 1, 1, 1]);
    }

    public function testRepairRoundAndRetryRoundShareOneSleep(): void
    {
        $this->transport->queue = [
            $this->llmReply('not json'),
            new HttpResponse(503),
            $this->llmReply(),
            $this->llmReply(),
        ];

        $results = $this->llmClient()->evaluateMany([$this->request('a'), $this->request('b')]);

        $this->assertContainsOnlyInstancesOf(Response::class, $results);
        $this->assertWaveSizes([2, 2]);
        $this->assertCount(1, $this->clock->sleeps);
    }

    public function testRepairedRequestRetriedAfter5xxIsNotRepairedAgain(): void
    {
        $this->transport->queue = [
            $this->llmReply('not json'),
            new HttpResponse(503),
            $this->llmReply('not json'),
            $this->llmReply(),
        ];

        $result = $this->llmClient()->evaluateMany([$this->request()])[0];

        $this->assertInstanceOf(InvalidAnswerException::class, $result);
        $this->assertWaveSizes([1, 1, 1]);
        $this->assertCount(1, $this->transport->queue);
        $this->assertStringContainsString('previous reply was rejected', $this->sentUserMessages()[2]);
    }

    public function testDiPreferences(): void
    {
        $xml = simplexml_load_file(dirname(__DIR__, 3) . '/etc/di.xml');
        $preferences = [];
        foreach ($xml->preference as $preference) {
            $preferences[(string)$preference['for']] = (string)$preference['type'];
        }

        $this->assertSame(Client::class, $preferences[ClientInterface::class]);
        $this->assertSame(
            \DmLab\TypeSafeCore\Model\Http\CurlMultiTransport::class,
            $preferences[\DmLab\TypeSafeCore\Model\Http\TransportInterface::class]
        );
    }
}
