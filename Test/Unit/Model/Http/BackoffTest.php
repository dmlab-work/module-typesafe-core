<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypeSafeCore\Test\Unit\Model\Http;

use DmLab\TypeSafeCore\Model\Http\Backoff;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class BackoffTest extends TestCase
{
    /**
     * @return array<string, array{int, float}>
     */
    public static function attempts(): array
    {
        return [
            'attempt 1' => [1, 0.5],
            'attempt 2' => [2, 1.0],
            'attempt 3' => [3, 2.0],
            'attempt 4' => [4, 4.0],
            'attempt 5' => [5, 8.0],
            'attempt 6' => [6, 16.0],
            'attempt 7' => [7, 30.0],
            'attempt 8' => [8, 30.0],
            'attempt 9' => [9, 30.0],
            'attempt 10' => [10, 30.0],
        ];
    }

    #[DataProvider('attempts')]
    public function testDelayStaysWithinEqualJitterBounds(int $attempt, float $cap): void
    {
        $backoff = new Backoff();
        for ($i = 0; $i < 200; $i++) {
            $delay = $backoff->delay($attempt);
            $this->assertGreaterThanOrEqual($cap / 2, $delay);
            $this->assertLessThanOrEqual($cap, $delay);
        }
    }

    public function testDelayIsJittered(): void
    {
        $backoff = new Backoff();
        $seen = [];
        for ($i = 0; $i < 50; $i++) {
            $seen[(string)$backoff->delay(6)] = true;
        }

        $this->assertGreaterThan(1, count($seen));
    }

    public function testHugeAndNonPositiveAttemptsAreClamped(): void
    {
        $backoff = new Backoff();

        $this->assertLessThanOrEqual(30.0, $backoff->delay(PHP_INT_MAX));
        $this->assertGreaterThanOrEqual(15.0, $backoff->delay(PHP_INT_MAX));
        $this->assertLessThanOrEqual(0.5, $backoff->delay(0));
        $this->assertLessThanOrEqual(0.5, $backoff->delay(-3));
    }

    /**
     * @return array<string, array{string|null, int|null}>
     */
    public static function retryAfterHeaders(): array
    {
        return [
            'seconds' => ['3', 3],
            'zero' => ['0', 0],
            'padded' => [' 7 ', 7],
            'large' => ['120', 120],
            'text' => ['abc', null],
            'negative' => ['-1', null],
            'fraction' => ['1.5', null],
            'http date' => ['Wed, 21 Oct 2026 07:28:00 GMT', null],
            'empty' => ['', null],
            'null' => [null, null],
        ];
    }

    #[DataProvider('retryAfterHeaders')]
    public function testParseRetryAfter(?string $header, ?int $expected): void
    {
        $this->assertSame($expected, (new Backoff())->parseRetryAfter($header));
    }
}
