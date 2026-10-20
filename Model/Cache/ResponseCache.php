<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypeSafeCore\Model\Cache;

use DmLab\TypeSafeCore\Model\Config;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Psr\Log\LoggerInterface;

/**
 * Raw TypeSafe response bodies keyed by the request cache key, kept for `cache_ttl_days`.
 */
class ResponseCache
{
    public const TABLE = 'dmlab_typesafe_response_cache';
    public const PURGE_BATCH = 1000;

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
     * Stored body for the key when younger than the TTL; null on miss, TTL 0 or a DB error.
     *
     * @param string $key
     * @return string|null
     */
    public function get(string $key): ?string
    {
        $ttlDays = $this->config->getCacheTtlDays();
        if ($ttlDays === 0) {
            return null;
        }

        try {
            $connection = $this->resource->getConnection();
            $select = $connection->select()
                ->from($this->resource->getTableName(self::TABLE), ['response'])
                ->where('cache_key = ?', $key)
                ->where('created_at >= ?', $this->cutoff($ttlDays));
            $body = $connection->fetchOne($select);
        } catch (\Exception $e) {
            $this->logger->warning('TypeSafe response cache read failed: ' . $e->getMessage());
            return null;
        }

        return is_string($body) ? $body : null;
    }

    /**
     * Upsert a response body; no-op at TTL 0, DB errors are logged only.
     *
     * @param string $key
     * @param string $consumer
     * @param string $model
     * @param string $body
     * @param int $inputTokens
     * @return void
     */
    public function save(string $key, string $consumer, string $model, string $body, int $inputTokens): void
    {
        if ($this->config->getCacheTtlDays() === 0) {
            return;
        }

        try {
            $this->resource->getConnection()->insertOnDuplicate(
                $this->resource->getTableName(self::TABLE),
                [
                    'cache_key' => $key,
                    'consumer' => $consumer,
                    'model' => $model,
                    'response' => $body,
                    'input_tokens' => max(0, $inputTokens),
                    'created_at' => $this->dateTime->gmtDate('Y-m-d H:i:s'),
                ],
                ['consumer', 'model', 'response', 'input_tokens', 'created_at']
            );
        } catch (\Exception $e) {
            $this->logger->warning('TypeSafe response cache write failed: ' . $e->getMessage());
        }
    }

    /**
     * Delete rows older than the TTL (every row at TTL 0) in chunks of PURGE_BATCH keys.
     *
     * @return int deleted rows
     */
    public function purgeExpired(): int
    {
        $ttlDays = $this->config->getCacheTtlDays();
        $cutoff = $ttlDays === 0 ? null : $this->cutoff($ttlDays);
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName(self::TABLE);

        $total = 0;
        do {
            $select = $connection->select()->from($table, ['cache_key'])->limit(self::PURGE_BATCH);
            if ($cutoff !== null) {
                $select->where('created_at < ?', $cutoff);
            }
            $keys = $connection->fetchCol($select);
            if ($keys === []) {
                break;
            }
            $deleted = (int)$connection->delete($table, ['cache_key IN (?)' => $keys]);
            $total += $deleted;
        } while ($deleted > 0 && count($keys) === self::PURGE_BATCH);

        return $total;
    }

    /**
     * UTC datetime `$ttlDays` days ago.
     *
     * @param int $ttlDays
     * @return string
     */
    private function cutoff(int $ttlDays): string
    {
        return (string)$this->dateTime->gmtDate(
            'Y-m-d H:i:s',
            $this->dateTime->gmtTimestamp() - $ttlDays * 86400
        );
    }
}
