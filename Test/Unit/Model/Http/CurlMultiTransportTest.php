<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypeSafeCore\Test\Unit\Model\Http;

use DmLab\TypeSafeCore\Model\Http\CurlMultiTransport;
use DmLab\TypeSafeCore\Model\Http\HttpRequest;
use DmLab\TypeSafeCore\Model\Http\HttpResponse;
use DmLab\TypeSafeCore\Test\Unit\Fixtures\LoopbackServer;
use PHPUnit\Framework\TestCase;

class CurlMultiTransportTest extends TestCase
{
    /**
     * @var LoopbackServer|null
     */
    private static ?LoopbackServer $server = null;

    /**
     * @var string|null
     */
    private static ?string $baseUrl = null;

    /**
     * @var CurlMultiTransport
     */
    private CurlMultiTransport $transport;

    public static function setUpBeforeClass(): void
    {
        self::$server = new LoopbackServer();
        self::$baseUrl = self::$server->start();
    }

    public static function tearDownAfterClass(): void
    {
        self::$server?->stop();
        self::$server = null;
        self::$baseUrl = null;
    }

    protected function setUp(): void
    {
        if (self::$baseUrl === null) {
            $this->markTestSkipped('Loopback PHP server could not start.');
        }
        $this->transport = new CurlMultiTransport(CURLPROTO_HTTP | CURLPROTO_HTTPS);
    }

    public function testDefaultRejectsPlainHttp(): void
    {
        $response = (new CurlMultiTransport())->sendMany([new HttpRequest('GET', self::$baseUrl . '/status/200')])[0];

        $this->assertSame(0, $response->getStatus());
        $this->assertSame(CURLE_UNSUPPORTED_PROTOCOL, $response->getErrno());
        $this->assertTrue($response->isTransportError());
    }

    public function testEmptyInputReturnsEmptyArray(): void
    {
        $this->assertSame([], $this->transport->sendMany([]));
    }

    public function testCapturesStatusBodyAndLowercasedHeaders(): void
    {
        $responses = $this->transport->sendMany([
            'a' => new HttpRequest('GET', self::$baseUrl . '/status/429?retry_after=7'),
        ]);

        $response = $responses['a'];
        $this->assertInstanceOf(HttpResponse::class, $response);
        $this->assertSame(429, $response->getStatus());
        $this->assertSame(['status' => 429], json_decode($response->getBody(), true));
        $this->assertSame('7', $response->getHeader('retry-after'));
        $this->assertSame('7', $response->getHeader('Retry-After'));
        $this->assertSame('Mixed-Case', $response->getHeaders()['x-loopback-header']);
        $this->assertSame(0, $response->getErrno());
        $this->assertSame('', $response->getError());
        $this->assertFalse($response->isTransportError());
    }

    public function testServerErrorIsNotATransportError(): void
    {
        $response = $this->transport->sendMany([new HttpRequest('GET', self::$baseUrl . '/status/503')])[0];

        $this->assertSame(503, $response->getStatus());
        $this->assertNull($response->getHeader('retry-after'));
        $this->assertFalse($response->isTransportError());
    }

    public function testRedirectIsNotFollowed(): void
    {
        $response = $this->transport->sendMany([
            new HttpRequest('GET', self::$baseUrl . '/status/302?location=/echo'),
        ])[0];

        $this->assertSame(302, $response->getStatus());
        $this->assertSame('/echo', $response->getHeader('location'));
        $this->assertSame('{"status":302}', $response->getBody());
    }

    public function testPostsBodyAndHeaders(): void
    {
        $response = $this->transport->sendMany([
            0 => new HttpRequest(
                'POST',
                self::$baseUrl . '/echo',
                ['Authorization' => 'Bearer test-key', 'Content-Type' => 'application/json', 'Expect' => ''],
                '{"model":"jev"}',
                5
            ),
        ])[0];

        $this->assertSame(200, $response->getStatus());
        $echo = json_decode($response->getBody(), true);
        $headers = array_change_key_case($echo['headers'], CASE_LOWER);
        $this->assertSame('POST', $echo['method']);
        $this->assertSame('{"model":"jev"}', $echo['body']);
        $this->assertSame('Bearer test-key', $headers['authorization']);
        $this->assertSame('application/json', $headers['content-type']);
        $this->assertArrayNotHasKey('expect', $headers);
    }

    public function testPreservesMixedKeys(): void
    {
        $responses = $this->transport->sendMany([
            'x' => new HttpRequest('GET', self::$baseUrl . '/status/201'),
            7 => new HttpRequest('GET', self::$baseUrl . '/status/202'),
            '9' => new HttpRequest('GET', self::$baseUrl . '/status/204'),
        ]);

        $this->assertSame(['x', 7, 9], array_keys($responses));
        $this->assertSame(201, $responses['x']->getStatus());
        $this->assertSame(202, $responses[7]->getStatus());
        $this->assertSame(204, $responses[9]->getStatus());
    }

    public function testRunsRequestsInParallel(): void
    {
        $requests = [];
        for ($i = 0; $i < 3; $i++) {
            $requests[] = new HttpRequest('GET', self::$baseUrl . '/sleep/400', [], null, 5);
        }

        $start = microtime(true);
        $responses = $this->transport->sendMany($requests);
        $elapsed = microtime(true) - $start;

        foreach ($responses as $response) {
            $this->assertSame(200, $response->getStatus());
        }
        $this->assertLessThan(1.0, $elapsed);
    }

    public function testTimeoutIsATransportError(): void
    {
        $request = new HttpRequest('GET', self::$baseUrl . '/sleep/3000', [], null, 1);
        $response = $this->transport->sendMany([$request])[0];

        $this->assertSame(0, $response->getStatus());
        $this->assertSame(CURLE_OPERATION_TIMEOUTED, $response->getErrno());
        $this->assertNotSame('', $response->getError());
        $this->assertTrue($response->isTransportError());
    }

    public function testClosedPortIsATransportError(): void
    {
        $port = LoopbackServer::freePort();
        $this->assertNotNull($port);

        $request = new HttpRequest('GET', 'http://127.0.0.1:' . $port . '/', [], null, 2);
        $response = $this->transport->sendMany([$request])[0];

        $this->assertSame(0, $response->getStatus());
        $this->assertSame(CURLE_COULDNT_CONNECT, $response->getErrno());
        $this->assertTrue($response->isTransportError());
    }
}
