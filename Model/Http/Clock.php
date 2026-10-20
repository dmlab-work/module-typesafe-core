<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypeSafeCore\Model\Http;

/**
 * Wall clock and sleep, injectable so retries are testable without waiting.
 */
class Clock
{
    /**
     * Current Unix time in seconds with microsecond precision.
     *
     * @return float
     */
    public function now(): float
    {
        return microtime(true);
    }

    /**
     * Block for the given seconds; no-op for zero or less.
     *
     * @param float $seconds
     * @return void
     */
    public function sleep(float $seconds): void
    {
        if ($seconds <= 0) {
            return;
        }
        usleep((int)round($seconds * 1000000));
    }
}
