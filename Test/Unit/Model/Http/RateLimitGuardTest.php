<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypeSafeCore\Test\Unit\Model\Http;

use DmLab\TypeSafeCore\Model\Http\RateLimitGuard;
use DmLab\TypeSafeCore\Test\Unit\Fake\ArrayCache;
use DmLab\TypeSafeCore\Test\Unit\Fake\FakeClock;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class RateLimitGuardTest extends TestCase
{
    /** @var ArrayCache */
    private ArrayCache $cache;

    /** @var FakeClock */
    private FakeClock $clock;

    protected function setUp(): void
    {
        $this->cache = new ArrayCache();
        $this->clock = new FakeClock();
    }

    private function guard(?LoggerInterface $logger = null): RateLimitGuard
    {
        return new RateLimitGuard($this->cache, $this->clock, $logger ?? $this->createStub(LoggerInterface::class));
    }

    public function testNotPausedByDefault(): void
    {
        $this->assertSame(0, $this->guard()->remainingSeconds());
    }

    public function testPauseUsesRetryAfterAndCountsDown(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with('TypeSafe rate limit reached; calls paused for 120 s.');
        $guard = $this->guard($logger);

        $guard->pause(120);
        $this->assertSame(120, $guard->remainingSeconds());
        $this->assertSame(120, $this->cache->lifetimes[RateLimitGuard::UNTIL_KEY]);

        $this->clock->time += 30.5;
        $this->assertSame(90, $guard->remainingSeconds());

        $this->clock->time += 89.5;
        $this->assertSame(0, $guard->remainingSeconds());
    }

    /**
     * @return array<string, array{int|null, int}>
     */
    public static function durationProvider(): array
    {
        return [
            'absent header' => [null, 60],
            'over the cap' => [86400, 900],
            'zero' => [0, 1],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('durationProvider')]
    public function testPauseDuration(?int $retryAfter, int $expected): void
    {
        $guard = $this->guard();
        $guard->pause($retryAfter);

        $this->assertSame($expected, $guard->remainingSeconds());
        $this->assertSame($expected, $this->cache->lifetimes[RateLimitGuard::UNTIL_KEY]);
    }

    public function testGarbageInTheCacheIsNotAPause(): void
    {
        $this->cache->data[RateLimitGuard::UNTIL_KEY] = 'soon';

        $this->assertSame(0, $this->guard()->remainingSeconds());
    }

    public function testCacheExceptionsNeverPropagate(): void
    {
        $this->cache->failWith = new \RuntimeException('valkey down');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->exactly(2))
            ->method('warning')
            ->with($this->stringContains('valkey down'));
        $guard = $this->guard($logger);

        $guard->pause(30);
        $this->assertSame(0, $guard->remainingSeconds());
    }
}
