<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypeSafeCore\Test\Unit\Api\Data;

use DmLab\TypeSafeCore\Api\Data\Answer\NoulAnswer;
use DmLab\TypeSafeCore\Api\Data\Response;
use PHPUnit\Framework\TestCase;

class ResponseTest extends TestCase
{
    public function testGetters(): void
    {
        $red = new NoulAnswer(0.9);
        $zero = new NoulAnswer(0.1);
        $response = new Response('jev-1.13.0', 120, false, ['red' => $red, '0' => $zero]);

        self::assertSame('jev-1.13.0', $response->getModel());
        self::assertSame(120, $response->getInputTokens());
        self::assertFalse($response->isCached());
        self::assertSame($red, $response->getAnswer('red'));
        self::assertSame($zero, $response->getAnswer('0'));
        self::assertSame(['red' => $red, 0 => $zero], $response->getAnswers());
    }

    public function testCached(): void
    {
        self::assertTrue((new Response('m', 0, true, []))->isCached());
    }

    public function testUnknownAnswerThrows(): void
    {
        $response = new Response('m', 0, false, ['red' => new NoulAnswer(0.5)]);

        $this->expectException(\OutOfBoundsException::class);
        $this->expectExceptionMessage('No answer for question "blue".');
        $response->getAnswer('blue');
    }

    public function testNonAnswerRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Response('m', 0, false, ['red' => 0.5]);
    }
}
