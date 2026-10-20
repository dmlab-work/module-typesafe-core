<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypeSafeCore\Test\Unit\Model\Connection;

use DmLab\TypeSafeCore\Exception\AuthenticationException;
use DmLab\TypeSafeCore\Exception\NotConfiguredException;
use DmLab\TypeSafeCore\Exception\RequestException;
use DmLab\TypeSafeCore\Exception\TransportException;
use DmLab\TypeSafeCore\Model\Config;
use DmLab\TypeSafeCore\Model\Connection\ModelLister;
use DmLab\TypeSafeCore\Model\Http\HttpResponse;
use DmLab\TypeSafeCore\Test\Unit\Fake\FakeTransport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ModelListerTest extends TestCase
{
    private const MODELS_BODY =
        '{"models":[{"name":"jev-latest","description":"x"},{"name":"jev-preview"},{"foo":1},"x"]}';

    /** @var string */
    private string $apiKey = 'ts-secret-key';

    /** @var int */
    private int $timeout = 20;

    /** @var FakeTransport */
    private FakeTransport $transport;

    protected function setUp(): void
    {
        $this->transport = new FakeTransport();
    }

    private function lister(): ModelLister
    {
        $config = $this->createStub(Config::class);
        $config->method('isEnabled')->willReturn(false);
        $config->method('getApiKey')->willReturnCallback(fn () => $this->apiKey);
        $config->method('getBaseUrl')->willReturn('https://api.example.test');
        $config->method('getTimeout')->willReturnCallback(fn () => $this->timeout);

        return new ModelLister($config, $this->transport);
    }

    public function testListsModelNamesWhileDisabled(): void
    {
        $this->transport->queue = [new HttpResponse(200, [], self::MODELS_BODY)];

        $this->assertSame(['jev-latest', 'jev-preview'], $this->lister()->listModels());
    }

    public function testSendsSingleGetWithBearerAndCappedTimeout(): void
    {
        $this->transport->queue = [new HttpResponse(200, [], self::MODELS_BODY)];

        $this->lister()->listModels();

        $this->assertCount(1, $this->transport->calls);
        $sent = $this->transport->sent();
        $this->assertCount(1, $sent);
        $this->assertSame('GET', $sent[0]->getMethod());
        $this->assertSame('https://api.example.test/v1/models', $sent[0]->getUrl());
        $this->assertSame('Bearer ts-secret-key', $sent[0]->getHeaders()['Authorization']);
        $this->assertNull($sent[0]->getBody());
        $this->assertSame(10, $sent[0]->getTimeout());
    }

    public function testTimeoutBelowCapIsKept(): void
    {
        $this->timeout = 3;
        $this->transport->queue = [new HttpResponse(200, [], self::MODELS_BODY)];

        $this->lister()->listModels();

        $this->assertSame(3, $this->transport->sent()[0]->getTimeout());
    }

    public function testNoKeyThrowsNotConfiguredWithoutHttp(): void
    {
        $this->apiKey = '';

        try {
            $this->lister()->listModels();
            $this->fail('Expected NotConfiguredException');
        } catch (NotConfiguredException $e) {
            $this->assertSame('TypeSafe API key is not set.', $e->getMessage());
        }
        $this->assertSame([], $this->transport->calls);
    }

    #[DataProvider('authStatuses')]
    public function testAuthFailure(int $status): void
    {
        $this->transport->queue = [new HttpResponse($status, [], '{"message":"bad key"}')];

        try {
            $this->lister()->listModels();
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

    public function testTransportErrorThrowsTransportException(): void
    {
        $this->transport->queue = [new HttpResponse(0, [], '', 7, 'Connection refused')];

        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('TypeSafe request failed: Connection refused');
        $this->lister()->listModels();
    }

    #[DataProvider('otherStatuses')]
    public function testOtherNonSuccessThrowsRequestException(int $status): void
    {
        $this->transport->queue = [new HttpResponse($status, [], 'oops')];

        try {
            $this->lister()->listModels();
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

    #[DataProvider('malformedBodies')]
    public function testMalformedBodyThrowsTransportException(string $body): void
    {
        $this->transport->queue = [new HttpResponse(200, [], $body)];

        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('TypeSafe returned a malformed response');
        $this->lister()->listModels();
    }

    public static function malformedBodies(): array
    {
        return [
            'not json' => ['<html>'],
            'no models' => ['{"data":[]}'],
            'scalar' => ['"x"'],
            'models not list' => ['{"models":1}'],
        ];
    }
}
