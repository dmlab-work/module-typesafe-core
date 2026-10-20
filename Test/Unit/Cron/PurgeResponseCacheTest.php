<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypeSafeCore\Test\Unit\Cron;

use DmLab\TypeSafeCore\Cron\PurgeResponseCache;
use DmLab\TypeSafeCore\Model\Cache\ResponseCache;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class PurgeResponseCacheTest extends TestCase
{
    public function testLogsDeletedCount(): void
    {
        $cache = $this->createMock(ResponseCache::class);
        $cache->expects($this->once())->method('purgeExpired')->willReturn(42);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('info')
            ->with('TypeSafe response cache purge removed 42 expired rows.');
        $logger->expects($this->never())->method('error');

        (new PurgeResponseCache($cache, $logger))->execute();
    }

    public function testNothingLoggedWhenNothingDeleted(): void
    {
        $cache = $this->createMock(ResponseCache::class);
        $cache->expects($this->once())->method('purgeExpired')->willReturn(0);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method('info');
        $logger->expects($this->never())->method('error');

        (new PurgeResponseCache($cache, $logger))->execute();
    }

    public function testThrowableIsLoggedAndSwallowed(): void
    {
        $cache = $this->createStub(ResponseCache::class);
        $cache->method('purgeExpired')->willThrowException(new \Error('db gone'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error')->with('TypeSafe response cache purge failed: db gone');
        $logger->expects($this->never())->method('info');

        (new PurgeResponseCache($cache, $logger))->execute();
    }

    public function testCrontabDeclaresJob(): void
    {
        $xml = simplexml_load_file(dirname(__DIR__, 3) . '/etc/crontab.xml');
        self::assertNotFalse($xml);

        $jobs = $xml->xpath('/config/group[@id="default"]/job[@name="dmlab_typesafe_purge_response_cache"]');
        $this->assertCount(1, $jobs);
        $job = $jobs[0];
        $this->assertSame(PurgeResponseCache::class, (string)$job['instance']);
        $this->assertSame('execute', (string)$job['method']);
        $this->assertSame('17 3 * * *', trim((string)$job->schedule));
        $this->assertTrue(method_exists((string)$job['instance'], (string)$job['method']));
    }
}
