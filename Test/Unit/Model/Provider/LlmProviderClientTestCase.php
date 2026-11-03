<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypeSafeCore\Test\Unit\Model\Provider;

use DmLab\TypeSafeCore\Api\Data\Question\ChoiceQuestion;
use DmLab\TypeSafeCore\Api\Data\Question\NoulQuestion;
use DmLab\TypeSafeCore\Api\Data\Question\ScoreQuestion;
use DmLab\TypeSafeCore\Api\Data\Request;
use DmLab\TypeSafeCore\Api\ProviderInterface;
use DmLab\TypeSafeCore\Exception\NotConfiguredException;
use DmLab\TypeSafeCore\Model\Cache\ResponseCache;
use DmLab\TypeSafeCore\Model\Client;
use DmLab\TypeSafeCore\Model\Config;
use DmLab\TypeSafeCore\Model\Http\Backoff;
use DmLab\TypeSafeCore\Model\Http\CircuitBreaker;
use DmLab\TypeSafeCore\Model\Http\HttpResponse;
use DmLab\TypeSafeCore\Model\Http\RateLimitGuard;
use DmLab\TypeSafeCore\Model\Json\CanonicalJson;
use DmLab\TypeSafeCore\Model\Llm\AnswerMapper;
use DmLab\TypeSafeCore\Model\Llm\PromptBuilder;
use DmLab\TypeSafeCore\Model\Llm\SchemaBuilder;
use DmLab\TypeSafeCore\Model\Provider\ProviderPool;
use DmLab\TypeSafeCore\Model\Provider\ProviderSettings;
use DmLab\TypeSafeCore\Model\RequestEncoder;
use DmLab\TypeSafeCore\Model\Usage\UsageLedger;
use DmLab\TypeSafeCore\Test\Unit\Fake\ArrayCache;
use DmLab\TypeSafeCore\Test\Unit\Fake\FakeClock;
use DmLab\TypeSafeCore\Test\Unit\Fake\FakeTransport;
use Magento\Framework\App\Config\ScopeConfigInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Runs an LLM provider through the real Client with FakeTransport and recorded bodies from Fixtures/Provider/<code>.
 */
abstract class LlmProviderClientTestCase extends TestCase
{
    /** @var array<string, string|null> config path => value */
    protected array $values = [];

    /** @var int */
    protected int $concurrency = 4;

    /** @var int */
    protected int $maxAttempts = 4;

    /** @var array<int, int|null> remaining budget per check; the last value repeats */
    protected array $budgets = [null];

    /** @var array<string, string> cache key => body */
    protected array $cached = [];

    /** @var array<int, array> */
    protected array $saved = [];

    /** @var array<int, array{string, int, bool, int}> consumer, input tokens, cache hit, output tokens */
    protected array $ledgerRecords = [];

    /** @var ProviderSettings */
    protected ProviderSettings $settings;

    /** @var Config */
    protected Config $config;

    /** @var RequestEncoder */
    protected RequestEncoder $encoder;

    /** @var ArrayCache */
    protected ArrayCache $circuitCache;

    /** @var FakeTransport */
    protected FakeTransport $transport;

    /** @var FakeClock */
    protected FakeClock $clock;

    /**
     * Provider code; also the fixture directory name.
     *
     * @return string
     */
    abstract protected function code(): string;

    /**
     * Provider under test over the given collaborators.
     *
     * @param ProviderSettings $settings
     * @param PromptBuilder $promptBuilder
     * @param SchemaBuilder $schemaBuilder
     * @param AnswerMapper $answerMapper
     * @param Backoff $backoff
     * @param FakeTransport $transport
     * @return ProviderInterface
     */
    abstract protected function createProvider(
        ProviderSettings $settings,
        PromptBuilder $promptBuilder,
        SchemaBuilder $schemaBuilder,
        AnswerMapper $answerMapper,
        Backoff $backoff,
        FakeTransport $transport
    ): ProviderInterface;

    protected function setUp(): void
    {
        $this->values[ProviderSettings::XML_PATH_PROVIDER] = $this->code();
        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(fn (string $path) => $this->values[$path] ?? null);
        $this->settings = new ProviderSettings($scopeConfig);

        $config = $this->createStub(Config::class);
        $config->method('isEnabled')->willReturn(true);
        $config->method('getModel')->willReturnCallback(fn () => $this->provider()->getModel());
        $config->method('getConcurrency')->willReturnCallback(fn () => $this->concurrency);
        $config->method('getMaxAttempts')->willReturnCallback(fn () => $this->maxAttempts);
        $config->method('getMonthlyTokenBudget')->willReturn(1000);

        $this->config = $config;
        $this->encoder = new RequestEncoder($config, new CanonicalJson());
        $this->circuitCache = new ArrayCache();
        $this->transport = new FakeTransport();
        $this->clock = new FakeClock();
    }

    public function testListModelsWithoutKeyThrowsNotConfiguredWithoutHttp(): void
    {
        unset($this->values['dmlab_typesafe/' . $this->code() . '/api_key']);
        $provider = $this->provider();

        try {
            $provider->listModels();
            $this->fail('Expected NotConfiguredException');
        } catch (NotConfiguredException $e) {
            $this->assertSame($provider->getLabel() . ' API key is not set.', $e->getMessage());
        }
        $this->assertSame([], $this->transport->calls);
    }

    /**
     * Fresh provider over the current settings and the shared transport.
     *
     * @return ProviderInterface
     */
    protected function provider(): ProviderInterface
    {
        return $this->createProvider(
            $this->settings,
            new PromptBuilder(),
            new SchemaBuilder(),
            new AnswerMapper(),
            new Backoff(),
            $this->transport
        );
    }

    /**
     * Real Client over the provider, with recording cache, ledger and circuit storage.
     *
     * @return Client
     */
    protected function client(): Client
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
        $ledger->method('getRemainingBudget')->willReturnCallback(
            fn (): ?int => count($this->budgets) > 1 ? array_shift($this->budgets) : $this->budgets[0]
        );

        $logger = $this->createStub(LoggerInterface::class);
        $provider = $this->provider();

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

    /**
     * Request with one question of each type and ids that are not plain JSON names; answers come as q1, q2, q3.
     *
     * @param string $title
     * @return Request
     */
    protected function request(string $title = 'Grey wool sofa, 3 seats'): Request
    {
        return new Request(
            ['title' => $title],
            [
                'red:rev' => new NoulQuestion('Is it red?'),
                'material' => new ChoiceQuestion('Main material', ['wool' => null, 'cotton' => 'Cotton fabric']),
                'comfort:412' => new ScoreQuestion('How comfortable is it?', ['low', 'medium', 'high']),
            ],
            'catalog_enrich'
        );
    }

    /**
     * Raw fixture body.
     *
     * @param string $name file name without `.json`
     * @return string
     */
    protected function fixture(string $name): string
    {
        $path = dirname(__DIR__, 2) . '/Fixtures/Provider/' . $this->code() . '/' . $name . '.json';
        $body = file_get_contents($path);
        $this->assertIsString($body, $path);

        return $body;
    }

    /**
     * HTTP response with a fixture body.
     *
     * @param int $status
     * @param string $name
     * @param array<string, string> $headers
     * @return HttpResponse
     */
    protected function reply(int $status, string $name, array $headers = []): HttpResponse
    {
        return new HttpResponse($status, $headers + ['Content-Type' => 'application/json'], $this->fixture($name));
    }

    /**
     * Decoded JSON body of every sent request, in send order.
     *
     * @return array[]
     */
    protected function sentBodies(): array
    {
        return array_map(
            static fn ($request) => json_decode((string)$request->getBody(), true),
            $this->transport->sent()
        );
    }

    /**
     * @param int[] $sizes
     */
    protected function assertWaveSizes(array $sizes): void
    {
        $this->assertSame($sizes, array_map('count', $this->transport->calls));
    }
}
