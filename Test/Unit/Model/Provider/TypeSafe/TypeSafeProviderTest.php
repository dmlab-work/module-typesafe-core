<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypeSafeCore\Test\Unit\Model\Provider\TypeSafe;

use DmLab\TypeSafeCore\Api\Data\Question\ChoiceQuestion;
use DmLab\TypeSafeCore\Api\Data\Question\NoulQuestion;
use DmLab\TypeSafeCore\Api\Data\Question\ScoreQuestion;
use DmLab\TypeSafeCore\Api\Data\Request;
use DmLab\TypeSafeCore\Api\ProviderInterface;
use DmLab\TypeSafeCore\Exception\AuthenticationException;
use DmLab\TypeSafeCore\Exception\NotConfiguredException;
use DmLab\TypeSafeCore\Exception\RequestException;
use DmLab\TypeSafeCore\Exception\TransportException;
use DmLab\TypeSafeCore\Model\Config;
use DmLab\TypeSafeCore\Model\Http\Backoff;
use DmLab\TypeSafeCore\Model\Http\HttpResponse;
use DmLab\TypeSafeCore\Model\Provider\ProviderPool;
use DmLab\TypeSafeCore\Model\Provider\ProviderSettings;
use DmLab\TypeSafeCore\Model\Provider\TypeSafe\ResponseParser;
use DmLab\TypeSafeCore\Model\Provider\TypeSafe\TypeSafeProvider;
use DmLab\TypeSafeCore\Test\Unit\Fake\FakeTransport;
use Magento\Framework\App\Config\ScopeConfigInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TypeSafeProviderTest extends TestCase
{
    private const MODEL = 'jev-1.13.0';
    private const MODELS_BODY =
        '{"models":[{"name":"jev-latest","description":"x"},{"name":"jev-preview"},{"foo":1},"x"]}';

    /** @var array<string, string> */
    private array $values = [
        Config::XML_PATH_API_KEY => 'ts-secret-key',
        Config::XML_PATH_BASE_URL => 'https://api.example.test/',
    ];

    /** @var FakeTransport */
    private FakeTransport $transport;

    protected function setUp(): void
    {
        $this->transport = new FakeTransport();
    }

    private function provider(): TypeSafeProvider
    {
        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(fn (string $path) => $this->values[$path] ?? null);

        return new TypeSafeProvider(
            new ProviderSettings($scopeConfig),
            new ResponseParser(),
            new Backoff(),
            $this->transport
        );
    }

    private function request(string|array $state = 'state', ?array $questions = null): Request
    {
        return new Request($state, $questions ?? ['q' => new NoulQuestion('Is it red?')], 'catalog_enrich');
    }

    private function body(Request $request): string
    {
        return (string)$this->provider()->buildRequest($request, self::MODEL, null)->getBody();
    }

    public function testIdentityAndSettings(): void
    {
        $provider = $this->provider();

        $this->assertInstanceOf(ProviderInterface::class, $provider);
        $this->assertSame('typesafe', $provider->getCode());
        $this->assertSame('TypeSafe', $provider->getLabel());
        $this->assertTrue($provider->isConfigured());
        $this->assertSame('jev-1.13.0', $provider->getModel());
        $this->assertSame('', $provider->cacheScope());

        $this->values[Config::XML_PATH_MODEL] = 'jev-1.14.0';
        $this->values[Config::XML_PATH_API_KEY] = ' ';
        $this->assertSame('jev-1.14.0', $this->provider()->getModel());
        $this->assertFalse($this->provider()->isConfigured());
    }

    public function testIsRegisteredInTheDiPool(): void
    {
        $xml = simplexml_load_file(dirname(__DIR__, 5) . '/etc/di.xml');
        $items = $xml->xpath(
            '/config/type[@name="' . ProviderPool::class . '"]/arguments/argument[@name="providers"]/item'
        );

        $map = array_combine(
            array_map(static fn ($item) => (string)$item['name'], $items),
            array_map(static fn ($item) => (string)$item, $items)
        );

        $this->assertSame(TypeSafeProvider::class, $map['typesafe']);
        $this->assertSame('typesafe', array_key_first($map));
    }

    public function testRequestShapeIsExact(): void
    {
        $this->values[Config::XML_PATH_TIMEOUT] = '33';

        $http = $this->provider()->buildRequest($this->request(), self::MODEL, 'ignored repair note');

        $this->assertSame('POST', $http->getMethod());
        $this->assertSame('https://api.example.test/v1/systemone', $http->getUrl());
        $this->assertSame(
            [
                'Authorization' => 'Bearer ts-secret-key',
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'Expect' => '',
            ],
            $http->getHeaders()
        );
        $this->assertSame(
            '{"model":"jev-1.13.0","state":"state","questions":{"q":{"type":"noul","instructions":"Is it red?"}}}',
            $http->getBody()
        );
        $this->assertSame(33, $http->getTimeout());
    }

    public function testBodyShape(): void
    {
        $body = $this->body(new Request(['name' => 'Shirt', 'attrs' => ['color' => 'red', 'size' => 'M']], [
            'red' => new NoulQuestion('Is it red?', 'Red', 'Not red'),
            'size' => new ChoiceQuestion('Size?', ['S' => null, 'M' => 'Medium']),
            'quality' => new ScoreQuestion('Quality?', ['bad', 'ok', 'good']),
        ], 'catalog_enrich', 'ignored'));

        $this->assertSame(
            '{"model":"jev-1.13.0","state":{"name":"Shirt","attrs":{"color":"red","size":"M"}},"questions":{'
            . '"red":{"type":"noul","instructions":"Is it red?","criteria":{"true":"Red","false":"Not red"}},'
            . '"size":{"type":"choice","instructions":"Size?","criteria":{"S":null,"M":"Medium"}},'
            . '"quality":{"type":"score","instructions":"Quality?","criteria":["bad","ok","good"]}}}',
            $body
        );
    }

    public function testBodyKeepsOptionOrderAndSendsNumericIdsAsObjects(): void
    {
        $body = $this->body($this->request('state', ['0' => new ChoiceQuestion('N?', ['1' => null, '0' => 'zero'])]));

        $this->assertSame(
            '{"model":"jev-1.13.0","state":"state","questions":{"0":'
            . '{"type":"choice","instructions":"N?","criteria":{"1":null,"0":"zero"}}}}',
            $body
        );
    }

    public function testBodyFlagsAndUtf8Substitution(): void
    {
        $this->assertStringContainsString(
            '{"url":"a/b","name":"Café","w":1.0}',
            $this->body($this->request(['url' => 'a/b', 'name' => 'Café', 'w' => 1.0]))
        );
        $this->assertStringContainsString(
            "\"name\":\"\u{FFFD}1\"",
            $this->body($this->request(['name' => "\xB1\x31"]))
        );
    }

    public function testNanIsARequestException(): void
    {
        try {
            $this->body($this->request(['weight' => NAN]));
            $this->fail('Expected RequestException');
        } catch (RequestException $e) {
            $this->assertStringStartsWith('TypeSafe request could not be encoded: ', $e->getMessage());
            $this->assertInstanceOf(\JsonException::class, $e->getPrevious());
        }
    }

    public function testBodyOverLimitIsARequestException(): void
    {
        $this->expectException(RequestException::class);
        $this->expectExceptionMessage('TypeSafe request body exceeds 32 MiB.');

        $this->body($this->request(str_repeat('a', TypeSafeProvider::MAX_BODY_BYTES)));
    }

    public function testParseResponseFallsBackToTheResolvedModel(): void
    {
        $body = '{"answers":{"q":{"type":"noul","noul":0.8}},"usage":{"input_tokens":12}}';

        $response = $this->provider()->parseResponse($body, $this->request(), 'jev-1.14.0', false);

        $this->assertSame('jev-1.14.0', $response->getModel());
        $this->assertSame(12, $response->getInputTokens());
        $this->assertSame(0.8, $response->getAnswer('q')->getProbability());
        $this->assertTrue($this->provider()->parseResponse($body, $this->request(), self::MODEL, true)->isCached());
    }

    public function testParseResponseRejectsMalformedBodies(): void
    {
        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('TypeSafe returned a malformed response');

        $this->provider()->parseResponse('{"model":"x"}', $this->request(), self::MODEL, false);
    }

    public function testUsageHasNoOutputTokens(): void
    {
        $provider = $this->provider();

        $this->assertSame(
            ['input' => 90, 'output' => 0],
            $provider->usage('{"answers":{},"usage":{"input_tokens":90,"output_tokens":5}}')
        );
        $this->assertSame(['input' => 0, 'output' => 0], $provider->usage('<html>'));
    }

    public function testErrorsUseTheClientDefaultsAndRetryAfterHeader(): void
    {
        $provider = $this->provider();

        $this->assertNull($provider->classifyError(new HttpResponse(401, [], '')));
        $this->assertSame(7, $provider->retryAfter(new HttpResponse(429, ['Retry-After' => '7'], '')));
        $this->assertNull($provider->retryAfter(new HttpResponse(429, ['Retry-After' => 'soon'], '')));
        $this->assertNull($provider->retryAfter(new HttpResponse(429, [], '')));
    }

    public function testListModelsSendsSingleGetWithBearerAndCappedTimeout(): void
    {
        $this->transport->queue = [new HttpResponse(200, [], self::MODELS_BODY)];

        $this->assertSame(['jev-latest', 'jev-preview'], $this->provider()->listModels());

        $this->assertCount(1, $this->transport->calls);
        $sent = $this->transport->sent();
        $this->assertCount(1, $sent);
        $this->assertSame('GET', $sent[0]->getMethod());
        $this->assertSame('https://api.example.test/v1/models', $sent[0]->getUrl());
        $this->assertSame('Bearer ts-secret-key', $sent[0]->getHeaders()['Authorization']);
        $this->assertNull($sent[0]->getBody());
        $this->assertSame(10, $sent[0]->getTimeout());
    }

    public function testListModelsKeepsATimeoutBelowTheCap(): void
    {
        $this->values[Config::XML_PATH_TIMEOUT] = '3';
        $this->transport->queue = [new HttpResponse(200, [], self::MODELS_BODY)];

        $this->provider()->listModels();

        $this->assertSame(3, $this->transport->sent()[0]->getTimeout());
    }

    public function testListModelsWithoutKeyThrowsNotConfiguredWithoutHttp(): void
    {
        $this->values[Config::XML_PATH_API_KEY] = '';

        try {
            $this->provider()->listModels();
            $this->fail('Expected NotConfiguredException');
        } catch (NotConfiguredException $e) {
            $this->assertSame('TypeSafe API key is not set.', $e->getMessage());
        }
        $this->assertSame([], $this->transport->calls);
    }

    #[DataProvider('authStatuses')]
    public function testListModelsAuthFailure(int $status): void
    {
        $this->transport->queue = [new HttpResponse($status, [], '{"message":"bad key"}')];

        try {
            $this->provider()->listModels();
            $this->fail('Expected AuthenticationException');
        } catch (AuthenticationException $e) {
            $this->assertSame($status, $e->getCode());
            $this->assertSame(sprintf('TypeSafe rejected the API key (HTTP %d).', $status), $e->getMessage());
        }
        $this->assertCount(1, $this->transport->calls);
    }

    public static function authStatuses(): array
    {
        return ['401' => [401], '403' => [403]];
    }

    public function testListModelsTransportError(): void
    {
        $this->transport->queue = [new HttpResponse(0, [], '', 7, 'Connection refused')];

        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('TypeSafe request failed: Connection refused');
        $this->provider()->listModels();
    }

    #[DataProvider('otherStatuses')]
    public function testListModelsOtherNonSuccess(int $status): void
    {
        $this->transport->queue = [new HttpResponse($status, [], 'oops')];

        try {
            $this->provider()->listModels();
            $this->fail('Expected RequestException');
        } catch (RequestException $e) {
            $this->assertSame($status, $e->getCode());
            $this->assertSame(sprintf('TypeSafe rejected the request (HTTP %d): oops', $status), $e->getMessage());
        }
        $this->assertCount(1, $this->transport->calls);
    }

    public static function otherStatuses(): array
    {
        return ['404' => [404], '429' => [429], '500' => [500], '302' => [302]];
    }

    #[DataProvider('malformedModelBodies')]
    public function testListModelsMalformedBody(string $body): void
    {
        $this->transport->queue = [new HttpResponse(200, [], $body)];

        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('TypeSafe returned a malformed response');
        $this->provider()->listModels();
    }

    public static function malformedModelBodies(): array
    {
        return [
            'not json' => ['<html>'],
            'no models' => ['{"data":[]}'],
            'scalar' => ['"x"'],
            'models not list' => ['{"models":1}'],
        ];
    }
}
