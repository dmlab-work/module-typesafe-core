<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypeSafeCore\Model\Http;

/**
 * Retry delays: exponential backoff with equal jitter, and `retry-after` parsing.
 */
class Backoff
{
    public const BASE_SECONDS = 0.5;
    public const MAX_SECONDS = 30.0;

    /**
     * Delay before the retry following the given attempt, in `[d/2, d]` where `d = min(30, 0.5 × 2^(attempt−1))`.
     *
     * @param int $attempt 1-based
     * @return float seconds
     */
    public function delay(int $attempt): float
    {
        $cap = min(self::MAX_SECONDS, self::BASE_SECONDS * 2.0 ** (max(1, $attempt) - 1));
        $capMs = (int)round($cap * 1000);

        return random_int(intdiv($capMs, 2), $capMs) / 1000;
    }

    /**
     * Seconds from a `retry-after` header; null unless it is a non-negative integer.
     *
     * @param string|null $header
     * @return int|null
     */
    public function parseRetryAfter(?string $header): ?int
    {
        if ($header === null) {
            return null;
        }
        $header = trim($header);

        return ctype_digit($header) ? (int)$header : null;
    }
}
