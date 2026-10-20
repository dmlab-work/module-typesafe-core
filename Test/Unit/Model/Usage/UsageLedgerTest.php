<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypeSafeCore\Test\Unit\Model\Usage;

use DmLab\TypeSafeCore\Api\UsageInterface;
use DmLab\TypeSafeCore\Model\Config;
use DmLab\TypeSafeCore\Model\Usage\UsageLedger;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\DB\Sql\Expression;
use Magento\Framework\Stdlib\DateTime\DateTime;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class UsageLedgerTest extends TestCase
{
    /** @var AdapterInterface */
    private AdapterInterface $connection;

    /** @var LoggerInterface */
    private LoggerInterface $logger;

    /** @var array<string, mixed> */
    private array $where = [];

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

    private function ledger(int $budget = 0): UsageLedger
    {
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($this->connection);
        $resource->method('getTableName')->willReturnCallback(static fn (string $name) => 'pfx_' . $name);

        $config = $this->createStub(Config::class);
        $config->method('getMonthlyTokenBudget')->willReturn($budget);

        $dateTime = $this->createStub(DateTime::class);
        $dateTime->method('gmtDate')->willReturnCallback(
            static fn (?string $format = null) => $format === 'Y-m' ? '2026-10' : '2026-10-19 12:00:00'
        );

        return new UsageLedger($resource, $config, $dateTime, $this->logger);
    }

    private function select(bool $mock = false): Select
    {
        $select = $mock ? $this->createMock(Select::class) : $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('order')->willReturnSelf();
        $select->method('where')->willReturnCallback(
            function (string $cond, $value) use ($select) {
                $this->where[$cond] = $value;
                return $select;
            }
        );
        $this->connection->method('select')->willReturn($select);

        return $select;
    }

    public function testImplementsUsageInterface(): void
    {
        self::assertInstanceOf(UsageInterface::class, $this->ledger());
    }

    /**
     * @return array<string, array{bool, int, array<string, int>, array<string, string>}>
     */
    public static function recordProvider(): array
    {
        return [
            'live call' => [
                false,
                120,
                ['requests' => 1, 'cache_hits' => 0, 'input_tokens' => 120],
                [
                    'requests' => 'requests + 1',
                    'cache_hits' => 'cache_hits + 0',
                    'input_tokens' => 'input_tokens + 120',
                ],
            ],
            'cache hit' => [
                true,
                0,
                ['requests' => 0, 'cache_hits' => 1, 'input_tokens' => 0],
                ['requests' => 'requests + 0', 'cache_hits' => 'cache_hits + 1', 'input_tokens' => 'input_tokens + 0'],
            ],
            'negative tokens clamped' => [
                false,
                -5,
                ['requests' => 1, 'cache_hits' => 0, 'input_tokens' => 0],
                ['requests' => 'requests + 1', 'cache_hits' => 'cache_hits + 0', 'input_tokens' => 'input_tokens + 0'],
            ],
        ];
    }

    /**
     * @param array<string, int> $counters
     * @param array<string, string> $increments
     */
    #[DataProvider('recordProvider')]
    public function testRecordUpsertsIncrements(bool $hit, int $tokens, array $counters, array $increments): void
    {
        $this->connectionMock()->expects(self::once())->method('insertOnDuplicate')->willReturnCallback(
            function (string $table, array $data, array $fields) use ($counters, $increments) {
                self::assertSame('pfx_dmlab_typesafe_usage', $table);
                self::assertSame(['period' => '2026-10', 'consumer' => 'catalog_enrich'] + $counters, $data);
                self::assertSame(array_keys($increments), array_keys($fields));
                foreach ($increments as $column => $sql) {
                    self::assertInstanceOf(Expression::class, $fields[$column]);
                    self::assertSame($sql, (string)$fields[$column]);
                }
                return 1;
            }
        );

        $this->ledger()->record('catalog_enrich', $tokens, $hit);
    }

    public function testRecordDbExceptionIsLoggedOnly(): void
    {
        $this->connection->method('insertOnDuplicate')->willThrowException(new \RuntimeException('db down'));
        $this->loggerMock()->expects(self::once())->method('warning')->with(self::stringContains('db down'));

        $this->ledger()->record('catalog_enrich', 5, false);
    }

    public function testMonthTokensForAllConsumers(): void
    {
        $connection = $this->connectionMock();
        $select = $this->select();
        $connection->expects(self::once())->method('fetchOne')->with($select)->willReturn('1500');

        self::assertSame(1500, $this->ledger()->getMonthTokens());
        self::assertSame(['period = ?' => '2026-10'], $this->where);
    }

    public function testMonthTokensForOneConsumer(): void
    {
        $this->select();
        $this->connection->method('fetchOne')->willReturn('700');

        self::assertSame(700, $this->ledger()->getMonthTokens('catalog_enrich'));
        self::assertSame(['period = ?' => '2026-10', 'consumer = ?' => 'catalog_enrich'], $this->where);
    }

    public function testMonthTokensWithNoRowsIsZero(): void
    {
        $this->select();
        $this->connection->method('fetchOne')->willReturn(null);

        self::assertSame(0, $this->ledger()->getMonthTokens());
    }

    public function testRemainingBudgetIsNullWhenUnlimited(): void
    {
        $this->connectionMock()->expects(self::never())->method('select');

        self::assertNull($this->ledger(0)->getRemainingBudget());
    }

    /**
     * @return array<string, array{int, string, int}>
     */
    public static function budgetProvider(): array
    {
        return [
            'positive' => [1000, '400', 600],
            'exactly used' => [1000, '1000', 0],
            'overshoot clamped' => [1000, '1300', 0],
        ];
    }

    #[DataProvider('budgetProvider')]
    public function testRemainingBudget(int $budget, string $spent, int $expected): void
    {
        $this->select();
        $this->connection->method('fetchOne')->willReturn($spent);

        self::assertSame($expected, $this->ledger($budget)->getRemainingBudget());
        self::assertSame(['period = ?' => '2026-10'], $this->where);
    }

    public function testGetRowsCastsAndOrdersByConsumer(): void
    {
        $select = $this->select(true);
        $select->expects(self::once())->method('order')->with('consumer ASC')->willReturnSelf();
        $this->connection->method('fetchAll')->willReturn([
            ['consumer' => 'catalog_enrich', 'requests' => '3', 'cache_hits' => '2', 'input_tokens' => '900'],
            ['consumer' => 'catalog_enrich_calibrate', 'requests' => '1', 'cache_hits' => '0', 'input_tokens' => '50'],
        ]);

        self::assertSame(
            [
                ['consumer' => 'catalog_enrich', 'requests' => 3, 'cache_hits' => 2, 'input_tokens' => 900],
                ['consumer' => 'catalog_enrich_calibrate', 'requests' => 1, 'cache_hits' => 0, 'input_tokens' => 50],
            ],
            $this->ledger()->getRows('2026-09')
        );
        self::assertSame(['period = ?' => '2026-09'], $this->where);
    }
}
