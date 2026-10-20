<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypeSafeCore\Test\Unit\Model\Http;

use DmLab\TypeSafeCore\Model\Http\CircuitBreaker;
use DmLab\TypeSafeCore\Test\Unit\Fake\ArrayCache;
use Magento\Framework\App\CacheInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class CircuitBreakerTest extends TestCase
{
    /** @var ArrayCache */
    private ArrayCache $cache;

    protected function setUp(): void
    {
        $this->cache = new ArrayCache();
    }

    private function breaker(?LoggerInterface $logger = null): CircuitBreaker
    {
        return new CircuitBreaker($this->cache, $logger ?? $this->createStub(LoggerInterface::class));
    }

    public function testClosedByDefault(): void
    {
        $this->assertFalse($this->breaker()->isOpen());
    }

    public function testOpensExactlyAtThresholdAcrossCalls(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with($this->stringContains('circuit breaker opened'));
        $breaker = $this->breaker($logger);

        $breaker->record(2, false);
        $breaker->record(1, false);
        $breaker->record(1, false);
        $this->assertFalse($breaker->isOpen());
        $this->assertSame('4', $this->cache->data[CircuitBreaker::FAILURES_KEY]);
        $this->assertSame(300, $this->cache->lifetimes[CircuitBreaker::FAILURES_KEY]);

        $breaker->record(1, false);
        $this->assertTrue($breaker->isOpen());
        $this->assertSame('1', $this->cache->data[CircuitBreaker::OPEN_KEY]);
        $this->assertSame(60, $this->cache->lifetimes[CircuitBreaker::OPEN_KEY]);
        $this->assertArrayNotHasKey(CircuitBreaker::FAILURES_KEY, $this->cache->data);
    }

    public function testOneRoundAboveThresholdOpens(): void
    {
        $breaker = $this->breaker();
        $breaker->record(7, false);

        $this->assertTrue($breaker->isOpen());
    }

    public function testSuccessResetsCounter(): void
    {
        $breaker = $this->breaker();
        $breaker->record(4, false);
        $breaker->record(1, true);
        $this->assertArrayNotHasKey(CircuitBreaker::FAILURES_KEY, $this->cache->data);

        $breaker->record(4, false);
        $this->assertFalse($breaker->isOpen());
        $breaker->record(1, false);
        $this->assertTrue($breaker->isOpen());
    }

    public function testRoundWithoutFailuresOrSuccessIsNoop(): void
    {
        $breaker = $this->breaker();
        $breaker->record(3, false);
        $breaker->record(0, false);

        $this->assertSame('3', $this->cache->data[CircuitBreaker::FAILURES_KEY]);
    }

    public function testIsOpenReadsTheFlag(): void
    {
        $this->cache->data[CircuitBreaker::OPEN_KEY] = '1';
        $this->assertTrue($this->breaker()->isOpen());

        $this->cache->data[CircuitBreaker::OPEN_KEY] = '0';
        $this->assertFalse($this->breaker()->isOpen());
    }

    public function testFlagSavedWithoutTagsAndLifetime60(): void
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('load')->willReturn('4');
        $cache->expects($this->once())
            ->method('save')
            ->with('1', CircuitBreaker::OPEN_KEY, [], 60);
        $cache->expects($this->once())->method('remove')->with(CircuitBreaker::FAILURES_KEY);

        (new CircuitBreaker($cache, $this->createStub(LoggerInterface::class)))->record(1, false);
    }

    public function testCacheExceptionsNeverPropagate(): void
    {
        $this->cache->failWith = new \RuntimeException('valkey down');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->exactly(3))
            ->method('warning')
            ->with($this->stringContains('valkey down'));
        $breaker = $this->breaker($logger);

        $this->assertFalse($breaker->isOpen());
        $breaker->record(5, false);
        $breaker->record(0, true);
    }
}
