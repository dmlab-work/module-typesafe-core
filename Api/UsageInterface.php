<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypeSafeCore\Api;

/**
 * AI token usage for the current UTC month.
 *
 * @api
 */
interface UsageInterface
{
    /**
     * Billed tokens (input + output) spent this month, by one consumer or all of them.
     *
     * @param string|null $consumer
     * @return int
     */
    public function getMonthTokens(?string $consumer = null): int;

    /**
     * Billed tokens (input + output) left in the monthly budget; null = unlimited.
     *
     * @return int|null
     */
    public function getRemainingBudget(): ?int;
}
