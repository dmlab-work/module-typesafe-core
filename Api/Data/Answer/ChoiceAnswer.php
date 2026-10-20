<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypeSafeCore\Api\Data\Answer;

/**
 * Chosen option with per-option probabilities.
 *
 * @api
 */
class ChoiceAnswer implements AnswerInterface
{
    /**
     * @param string $choice
     * @param array $probabilities option => probability
     * @param float $confidence
     */
    public function __construct(
        private readonly string $choice,
        private readonly array $probabilities,
        private readonly float $confidence
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getType(): string
    {
        return 'choice';
    }

    /**
     * Most likely option.
     *
     * @return string
     */
    public function getChoice(): string
    {
        return $this->choice;
    }

    /**
     * Option => probability (numeric options become int keys).
     *
     * @return array
     */
    public function getProbabilities(): array
    {
        return $this->probabilities;
    }

    /**
     * Probability of one option; 0.0 for an unknown option.
     *
     * @param string $option
     * @return float
     */
    public function getProbability(string $option): float
    {
        return (float)($this->probabilities[$option] ?? 0.0);
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
