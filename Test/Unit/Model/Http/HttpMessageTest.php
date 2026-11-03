<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypeSafeCore\Test\Unit\Model\Http;

use DmLab\TypeSafeCore\Model\Http\HttpRequest;
use DmLab\TypeSafeCore\Model\Http\HttpResponse;
use PHPUnit\Framework\TestCase;

class HttpMessageTest extends TestCase
{
    public function testRequestGetters(): void
    {
        $request = new HttpRequest('POST', 'https://api.example/v1', ['Accept' => 'application/json'], '{}', 12);

        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('https://api.example/v1', $request->getUrl());
        $this->assertSame(['Accept' => 'application/json'], $request->getHeaders());
        $this->assertSame('{}', $request->getBody());
        $this->assertSame(12, $request->getTimeout());
    }

    public function testRequestDefaults(): void
    {
        $request = new HttpRequest('GET', 'https://api.example/v1/models');

        $this->assertSame([], $request->getHeaders());
        $this->assertNull($request->getBody());
        $this->assertSame(30, $request->getTimeout());
    }

    public function testResponseLowercasesHeaderNames(): void
    {
        $response = new HttpResponse(200, ['Retry-After' => '3'], 'ok');

        $this->assertSame(['retry-after' => '3'], $response->getHeaders());
        $this->assertSame('3', $response->getHeader('RETRY-AFTER'));
        $this->assertNull($response->getHeader('x-missing'));
        $this->assertSame('ok', $response->getBody());
        $this->assertFalse($response->isTransportError());
    }

    public function testStatusZeroOrErrnoIsTransportError(): void
    {
        $this->assertTrue((new HttpResponse(0))->isTransportError());
        $this->assertTrue((new HttpResponse(0, [], '', 7, 'refused'))->isTransportError());
        $this->assertSame(7, (new HttpResponse(0, [], '', 7, 'refused'))->getErrno());
        $this->assertSame('refused', (new HttpResponse(0, [], '', 7, 'refused'))->getError());
    }

    public function testMessagesArePublicApiOfTheProviderContract(): void
    {
        foreach ([HttpRequest::class, HttpResponse::class] as $class) {
            self::assertStringContainsString('@api', (string)(new \ReflectionClass($class))->getDocComment());
        }
    }
}
