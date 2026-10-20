<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypeSafeCore\Test\Unit\Model\Cache;

use DmLab\TypeSafeCore\Model\Cache\ResponseCache;
use DmLab\TypeSafeCore\Model\Config;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Stdlib\DateTime\DateTime;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ResponseCacheTest extends TestCase
{
    private const NOW = 1760000000; // 2025-10-09 08:53:20 UTC

    /** @var AdapterInterface */
    private AdapterInterface $connection;

    /** @var LoggerInterface */
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->connection = $this->createStub(AdapterInterface::class);
        $this->logger = $this->createStub(LoggerInterface::class);
    }

    private function connectionMock(): AdapterInterface&MockObject
    {
        $this->connection = $this->createMock(AdapterInterface::class);

        return $this->connection;
    }

    private function loggerMock(): LoggerInterface&MockObject
    {
        $this->logger = $this->createMock(LoggerInterface::class);

        return $this->logger;
    }

    private function cache(int $ttlDays): ResponseCache
    {
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($this->connection);
        $resource->method('getTableName')->willReturnCallback(static fn (string $name) => 'pfx_' . $name);

        $config = $this->createStub(Config::class);
        $config->method('getCacheTtlDays')->willReturn($ttlDays);

        $dateTime = $this->createStub(DateTime::class);
        $dateTime->method('gmtTimestamp')->willReturn(self::NOW);
        $dateTime->method('gmtDate')->willReturnCallback(
            static fn (?string $format = null, $input = null) => gmdate($format ?? 'Y-m-d H:i:s', $input ?? self::NOW)
        );

        return new ResponseCache($resource, $config, $dateTime, $this->logger);
    }

    public function testGetReadsOnlyRowsWithinTheTtl(): void
    {
        $where = [];
        $select = $this->createMock(Select::class);
        $select->expects(self::once())->method('from')
            ->with('pfx_dmlab_typesafe_response_cache', ['response'])->willReturnSelf();
        $select->method('where')->willReturnCallback(
            function (string $cond, $value) use (&$where, $select) {
                $where[$cond] = $value;
                return $select;
            }
        );
        $connection = $this->connectionMock();
        $connection->method('select')->willReturn($select);
        $connection->expects(self::once())->method('fetchOne')->with($select)->willReturn('{"answers":{}}');

        self::assertSame('{"answers":{}}', $this->cache(30)->get('abc'));
        self::assertSame(
            ['cache_key = ?' => 'abc', 'created_at >= ?' => gmdate('Y-m-d H:i:s', self::NOW - 30 * 86400)],
            $where
        );
    }

    public function testGetMissReturnsNull(): void
    {
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $this->connection->method('select')->willReturn($select);
        $this->connection->method('fetchOne')->willReturn(false);

        self::assertNull($this->cache(30)->get('abc'));
    }

    public function testTtlZeroGetAndSaveNeverTouchTheDatabase(): void
    {
        $connection = $this->connectionMock();
        $connection->expects(self::never())->method('select');
        $connection->expects(self::never())->method('insertOnDuplicate');
        $cache = $this->cache(0);

        self::assertNull($cache->get('abc'));
        $cache->save('abc', 'catalog_enrich', 'jev-1.13.0', '{}', 10);
    }

    public function testSaveUpsertsTheRow(): void
    {
        $this->connectionMock()->expects(self::once())->method('insertOnDuplicate')->with(
            'pfx_dmlab_typesafe_response_cache',
            [
                'cache_key' => 'abc',
                'consumer' => 'catalog_enrich',
                'model' => 'jev-1.13.0',
                'response' => '{"answers":{}}',
                'input_tokens' => 42,
                'created_at' => gmdate('Y-m-d H:i:s', self::NOW),
            ],
            ['consumer', 'model', 'response', 'input_tokens', 'created_at']
        )->willReturn(1);

        $this->cache(30)->save('abc', 'catalog_enrich', 'jev-1.13.0', '{"answers":{}}', 42);
    }

    public function testGetDbExceptionIsLoggedAsMiss(): void
    {
        $this->connection->method('select')->willThrowException(new \RuntimeException('db down'));
        $this->loggerMock()->expects(self::once())->method('warning')
            ->with(self::stringContains('db down'));

        self::assertNull($this->cache(30)->get('abc'));
    }

    public function testSaveDbExceptionIsLoggedOnly(): void
    {
        $this->connection->method('insertOnDuplicate')->willThrowException(new \RuntimeException('db down'));
        $this->loggerMock()->expects(self::once())->method('warning')
            ->with(self::stringContains('db down'));

        $this->cache(30)->save('abc', 'catalog_enrich', 'jev-1.13.0', '{}', 1);
    }

    /**
     * Connection stub answering purge selects with $batches in order; records where clauses and deleted keys.
     *
     * @param array<int, string[]> $batches
     * @param array $wheres
     * @param array $limits
     * @param array $deletes
     * @return void
     */
    private function purgeConnection(array $batches, array &$wheres, array &$limits, array &$deletes): void
    {
        $connection = $this->connection;
        $connection->method('select')->willReturnCallback(function () use (&$wheres, &$limits) {
            $select = $this->createStub(Select::class);
            $select->method('from')->willReturnCallback(function ($table, $cols) use ($select) {
                self::assertSame(['pfx_dmlab_typesafe_response_cache', ['cache_key']], [$table, $cols]);
                return $select;
            });
            $select->method('limit')->willReturnCallback(function ($limit) use ($select, &$limits) {
                $limits[] = $limit;
                return $select;
            });
            $select->method('where')->willReturnCallback(function ($cond, $value) use ($select, &$wheres) {
                $wheres[] = [$cond => $value];
                return $select;
            });
            return $select;
        });
        $connection->method('fetchCol')->willReturnCallback(static function () use (&$batches) {
            return array_shift($batches) ?? [];
        });
        $connection->method('delete')->willReturnCallback(function ($table, $where) use (&$deletes) {
            self::assertSame('pfx_dmlab_typesafe_response_cache', $table);
            $deletes[] = $where;
            return count($where['cache_key IN (?)']);
        });
    }

    public function testPurgeDeletesRowsOlderThanTheTtlInChunks(): void
    {
        $full = array_map(static fn (int $i) => 'k' . $i, range(1, ResponseCache::PURGE_BATCH));
        $wheres = $limits = $deletes = [];
        $this->purgeConnection([$full, ['x', 'y']], $wheres, $limits, $deletes);

        self::assertSame(ResponseCache::PURGE_BATCH + 2, $this->cache(7)->purgeExpired());
        $cutoff = ['created_at < ?' => gmdate('Y-m-d H:i:s', self::NOW - 7 * 86400)];
        self::assertSame([$cutoff, $cutoff], $wheres);
        self::assertSame([ResponseCache::PURGE_BATCH, ResponseCache::PURGE_BATCH], $limits);
        self::assertSame([['cache_key IN (?)' => $full], ['cache_key IN (?)' => ['x', 'y']]], $deletes);
    }

    public function testPurgeLoopsUntilNoKeysAreLeft(): void
    {
        $full = array_fill(0, ResponseCache::PURGE_BATCH, 'k');
        $wheres = $limits = $deletes = [];
        $this->purgeConnection([$full, $full], $wheres, $limits, $deletes);

        self::assertSame(2 * ResponseCache::PURGE_BATCH, $this->cache(7)->purgeExpired());
        self::assertCount(3, $limits);
        self::assertCount(2, $deletes);
    }

    public function testPurgeWithNothingExpiredDeletesNothing(): void
    {
        $wheres = $limits = $deletes = [];
        $this->purgeConnection([], $wheres, $limits, $deletes);

        self::assertSame(0, $this->cache(7)->purgeExpired());
        self::assertSame([], $deletes);
    }

    public function testPurgeAtTtlZeroDeletesEveryRowInChunks(): void
    {
        $wheres = $limits = $deletes = [];
        $this->purgeConnection([['a', 'b', 'c']], $wheres, $limits, $deletes);

        self::assertSame(3, $this->cache(0)->purgeExpired());
        self::assertSame([], $wheres);
        self::assertSame([['cache_key IN (?)' => ['a', 'b', 'c']]], $deletes);
    }

    public function testPurgeStopsWhenAChunkDeletesNothing(): void
    {
        $full = array_fill(0, ResponseCache::PURGE_BATCH, 'k');
        $connection = $this->connectionMock();
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('limit')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $connection->method('select')->willReturn($select);
        $connection->expects(self::once())->method('fetchCol')->willReturn($full);
        $connection->expects(self::once())->method('delete')->willReturn(0);

        self::assertSame(0, $this->cache(7)->purgeExpired());
    }
}
