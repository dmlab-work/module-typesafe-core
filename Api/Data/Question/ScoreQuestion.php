<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypeSafeCore\Api\Data\Question;

use DmLab\TypeSafeCore\Api\Data\ContentValidationTrait;

/**
 * Rate on an ordered list of 2–10 levels.
 *
 * @api
 */
class ScoreQuestion implements QuestionInterface
{
    use ContentValidationTrait;

    public const MIN_LEVELS = 2;
    public const MAX_LEVELS = 10;

    /**
     * @param string|array $instructions
     * @param array $levels list of string|array levels, lowest first
     * @throws \InvalidArgumentException
     */
    public function __construct(
        private readonly string|array $instructions,
        private readonly array $levels
    ) {
        $this->assertContent($instructions, 'Score instructions');
        if (!array_is_list($levels)) {
            throw new \InvalidArgumentException('Score levels must be a list.');
        }
        $count = count($levels);
        if ($count < self::MIN_LEVELS || $count > self::MAX_LEVELS) {
            throw new \InvalidArgumentException(
                sprintf('Score needs %d–%d levels, %d given.', self::MIN_LEVELS, self::MAX_LEVELS, $count)
            );
        }
        foreach ($levels as $index => $level) {
            $this->assertContent($level, sprintf('Score level %d', $index));
        }
    }

    /**
     * @inheritDoc
     */
    public function toArray(): array
    {
        return ['type' => 'score', 'instructions' => $this->instructions, 'criteria' => array_values($this->levels)];
    }
}
