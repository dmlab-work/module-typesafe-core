<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypeSafeCore\Model\Http;

use Magento\Framework\App\CacheInterface;
use Psr\Log\LoggerInterface;

/**
 * Opens for OPEN_SECONDS after THRESHOLD consecutive failing rounds (transport/5xx), shared via the cache.
 */
class CircuitBreaker
{
    public const THRESHOLD = 5;
    public const OPEN_SECONDS = 60;
    public const FAILURES_KEY = 'dmlab_typesafe_circuit_failures';
    public const OPEN_KEY = 'dmlab_typesafe_circuit_open';
    public const FAILURES_LIFETIME = 300;

    /**
     * @param CacheInterface $cache
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Whether calls must be refused; a cache error counts as closed.
     *
     * @return bool
     */
    public function isOpen(): bool
    {
        try {
            return $this->cache->load(self::OPEN_KEY) === '1';
        } catch (\Exception $e) {
            $this->logger->warning('TypeSafe circuit breaker read failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Record one round: any success resets the counter, otherwise failures accumulate until the circuit opens.
     *
     * @param int $failures
     * @param bool $anySuccess
     * @return void
     */
    public function record(int $failures, bool $anySuccess): void
    {
        try {
            if ($anySuccess) {
                $this->cache->remove(self::FAILURES_KEY);
                return;
            }
            if ($failures <= 0) {
                return;
            }

            $count = (int)$this->cache->load(self::FAILURES_KEY) + $failures;
            if ($count < self::THRESHOLD) {
                $this->cache->save((string)$count, self::FAILURES_KEY, [], self::FAILURES_LIFETIME);
                return;
            }

            $this->cache->save('1', self::OPEN_KEY, [], self::OPEN_SECONDS);
            $this->cache->remove(self::FAILURES_KEY);
            $this->logger->warning(sprintf(
                'TypeSafe circuit breaker opened for %d s after %d consecutive failures.',
                self::OPEN_SECONDS,
                $count
            ));
        } catch (\Exception $e) {
            $this->logger->warning('TypeSafe circuit breaker write failed: ' . $e->getMessage());
        }
    }
}
