<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypeSafeCore\Test\Unit\Model\Http;

use DmLab\TypeSafeCore\Model\Http\Clock;
use PHPUnit\Framework\TestCase;

class ClockTest extends TestCase
{
    public function testNowIsCurrentTime(): void
    {
        $before = microtime(true);
        $now = (new Clock())->now();

        $this->assertGreaterThanOrEqual($before, $now);
        $this->assertLessThanOrEqual(microtime(true), $now);
    }

    public function testSleepWaits(): void
    {
        $start = microtime(true);
        (new Clock())->sleep(0.02);

        $this->assertGreaterThanOrEqual(0.015, microtime(true) - $start);
    }

    public function testNonPositiveSleepIsNoop(): void
    {
        $clock = new Clock();
        $start = microtime(true);
        $clock->sleep(0.0);
        $clock->sleep(-5.0);

        $this->assertLessThan(0.01, microtime(true) - $start);
    }
}
