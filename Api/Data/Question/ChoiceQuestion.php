<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypeSafeCore\Api\Data\Question;

use DmLab\TypeSafeCore\Api\Data\ContentValidationTrait;

/**
 * Pick one of 1–255 options. Option order is shown to the model.
 *
 * @api
 */
class ChoiceQuestion implements QuestionInterface
{
    use ContentValidationTrait;

    public const MAX_OPTIONS = 255;

    /**
     * @param string|array $instructions
     * @param array $criteria option => string|array|null description, in display order
     * @throws \InvalidArgumentException
     */
    public function __construct(
        private readonly string|array $instructions,
        private readonly array $criteria
    ) {
        $this->assertContent($instructions, 'Choice instructions');
        $count = count($criteria);
        if ($count < 1 || $count > self::MAX_OPTIONS) {
            throw new \InvalidArgumentException(
                sprintf('Choice needs 1–%d options, %d given.', self::MAX_OPTIONS, $count)
            );
        }
        foreach ($criteria as $option => $description) {
            if (trim((string)$option) === '') {
                throw new \InvalidArgumentException('Choice option must be a non-empty string.');
            }
            if ($description !== null) {
                $this->assertContent($description, sprintf('Choice criterion "%s"', $option));
            }
        }
    }

    /**
     * @inheritDoc
     */
    public function toArray(): array
    {
        // (object) keeps numeric-string options ("0") as JSON object keys in their order.
        return ['type' => 'choice', 'instructions' => $this->instructions, 'criteria' => (object)$this->criteria];
    }
}
