<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypeSafeCore\Api\Data\Answer;

/**
 * Expected level index with per-level probabilities.
 *
 * @api
 */
class ScoreAnswer implements AnswerInterface
{
    /**
     * @param float $score
     * @param array $probabilities level index => probability
     * @param float $confidence
     */
    public function __construct(
        private readonly float $score,
        private readonly array $probabilities,
        private readonly float $confidence
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getType(): string
    {
        return 'score';
    }

    /**
     * Expected level index (fractional).
     *
     * @return float
     */
    public function getScore(): float
    {
        return $this->score;
    }

    /**
     * Level index => probability.
     *
     * @return array
     */
    public function getProbabilities(): array
    {
        return $this->probabilities;
    }

    /**
     * Model confidence, 0..1.
     *
     * @return float
     */
    public function getConfidence(): float
    {
        return $this->confidence;
    }
}
