<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypeSafeCore\Test\Unit\Fake;

use DmLab\TypeSafeCore\Model\Http\Clock;

/**
 * Clock that records sleeps and advances virtual time instead of blocking.
 */
class FakeClock extends Clock
{
    /** @var float[] */
    public array $sleeps = [];

    /**
     * @param float $time
     */
    public function __construct(public float $time = 1_000_000.0)
    {
    }

    /**
     * @inheritDoc
     */
    public function now(): float
    {
        return $this->time;
    }

    /**
     * @inheritDoc
     */
    public function sleep(float $seconds): void
    {
        $this->sleeps[] = $seconds;
        $this->time += max(0.0, $seconds);
    }
}
