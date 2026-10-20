<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypeSafeCore\Model\Usage;

use DmLab\TypeSafeCore\Api\UsageInterface;
use DmLab\TypeSafeCore\Model\Config;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Sql\Expression;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Psr\Log\LoggerInterface;

/**
 * Monthly (UTC) usage counters per consumer and the token budget derived from them.
 */
class UsageLedger implements UsageInterface
{
    public const TABLE = 'dmlab_typesafe_usage';
    public const USD_PER_MILLION_TOKENS = 0.042;

    /**
     * @param ResourceConnection $resource
     * @param Config $config
     * @param DateTime $dateTime
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly Config $config,
        private readonly DateTime $dateTime,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Count one live call (with its tokens) or one cache hit; DB errors are logged only.
     *
     * @param string $consumer
     * @param int $inputTokens
     * @param bool $cacheHit
     * @return void
     */
    public function record(string $consumer, int $inputTokens, bool $cacheHit): void
    {
        $requests = $cacheHit ? 0 : 1;
        $hits = $cacheHit ? 1 : 0;
        $tokens = max(0, $inputTokens);

        try {
            $this->resource->getConnection()->insertOnDuplicate(
                $this->resource->getTableName(self::TABLE),
                [
                    'period' => $this->currentPeriod(),
                    'consumer' => $consumer,
                    'requests' => $requests,
                    'cache_hits' => $hits,
                    'input_tokens' => $tokens,
                ],
                [
                    'requests' => new Expression('requests + ' . $requests),
                    'cache_hits' => new Expression('cache_hits + ' . $hits),
                    'input_tokens' => new Expression('input_tokens + ' . $tokens),
                ]
            );
        } catch (\Exception $e) {
            $this->logger->warning('TypeSafe usage ledger write failed: ' . $e->getMessage());
        }
    }

    /**
     * @inheritDoc
     */
    public function getMonthTokens(?string $consumer = null): int
    {
        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from($this->resource->getTableName(self::TABLE), [new Expression('SUM(input_tokens)')])
            ->where('period = ?', $this->currentPeriod());
        if ($consumer !== null) {
            $select->where('consumer = ?', $consumer);
        }

        return (int)$connection->fetchOne($select);
    }

    /**
     * @inheritDoc
     */
    public function getRemainingBudget(): ?int
    {
        $budget = $this->config->getMonthlyTokenBudget();
        if ($budget === 0) {
            return null;
        }

        return max(0, $budget - $this->getMonthTokens());
    }

    /**
     * Usage rows of a period (`Y-m`), ordered by consumer.
     *
     * @param string $period
     * @return array<int, array{consumer: string, requests: int, cache_hits: int, input_tokens: int}>
     */
    public function getRows(string $period): array
    {
        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from(
                $this->resource->getTableName(self::TABLE),
                ['consumer', 'requests', 'cache_hits', 'input_tokens']
            )
            ->where('period = ?', $period)
            ->order('consumer ASC');

        $rows = [];
        foreach ($connection->fetchAll($select) as $row) {
            $rows[] = [
                'consumer' => (string)$row['consumer'],
                'requests' => (int)$row['requests'],
                'cache_hits' => (int)$row['cache_hits'],
                'input_tokens' => (int)$row['input_tokens'],
            ];
        }

        return $rows;
    }

    /**
     * Current UTC month as `Y-m`.
     *
     * @return string
     */
    private function currentPeriod(): string
    {
        return (string)$this->dateTime->gmtDate('Y-m');
    }
}
