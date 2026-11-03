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
 * Remembers a final rate limit across calls via the cache, so later calls refuse without HTTP until it ends.
 */
class RateLimitGuard
{
    public const DEFAULT_SECONDS = 60;
    public const MAX_SECONDS = 900;
    public const UNTIL_KEY = 'dmlab_typesafe_rate_limited_until';

    /**
     * @param CacheInterface $cache
     * @param Clock $clock
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly CacheInterface $cache,
        private readonly Clock $clock,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Seconds left until calls may resume; 0 when not rate-limited or on a cache error.
     *
     * @return int
     */
    public function remainingSeconds(): int
    {
        try {
            $until = $this->cache->load(self::UNTIL_KEY);
        } catch (\Exception $e) {
            $this->logger->warning('AI client rate limit read failed: ' . $e->getMessage());
            return 0;
        }
        if (!is_numeric($until)) {
            return 0;
        }

        return max(0, (int)ceil((float)$until - $this->clock->now()));
    }

    /**
     * Pause calls for retry-after seconds (DEFAULT_SECONDS when absent), capped at MAX_SECONDS.
     *
     * @param int|null $retryAfter
     * @return void
     */
    public function pause(?int $retryAfter): void
    {
        $seconds = min(self::MAX_SECONDS, max(1, $retryAfter ?? self::DEFAULT_SECONDS));
        try {
            $until = $this->clock->now() + $seconds;
            $this->cache->save(sprintf('%.3F', $until), self::UNTIL_KEY, [], $seconds);
            $this->logger->warning(sprintf('AI client rate limit reached; calls paused for %d s.', $seconds));
        } catch (\Exception $e) {
            $this->logger->warning('AI client rate limit write failed: ' . $e->getMessage());
        }
    }
}
