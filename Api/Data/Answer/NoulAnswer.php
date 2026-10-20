<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypeSafeCore\Api\Data\Answer;

/**
 * Probability that the statement is true.
 *
 * @api
 */
class NoulAnswer implements AnswerInterface
{
    /**
     * @param float $probability
     */
    public function __construct(
        private readonly float $probability
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getType(): string
    {
        return 'noul';
    }

    /**
     * Probability of "true", 0..1.
     *
     * @return float
     */
    public function getProbability(): float
    {
        return $this->probability;
    }
}
