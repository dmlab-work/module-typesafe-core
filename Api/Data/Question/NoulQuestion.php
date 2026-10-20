<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypeSafeCore\Api\Data\Question;

use DmLab\TypeSafeCore\Api\Data\ContentValidationTrait;

/**
 * Yes/no question answered with a probability of "true".
 *
 * @api
 */
class NoulQuestion implements QuestionInterface
{
    use ContentValidationTrait;

    /**
     * @param string|array $instructions
     * @param string|array|null $whenTrue
     * @param string|array|null $whenFalse
     * @throws \InvalidArgumentException
     */
    public function __construct(
        private readonly string|array $instructions,
        private readonly string|array|null $whenTrue = null,
        private readonly string|array|null $whenFalse = null
    ) {
        $this->assertContent($instructions, 'Noul instructions');
        if ($whenTrue !== null) {
            $this->assertContent($whenTrue, 'Noul "true" criterion');
        }
        if ($whenFalse !== null) {
            $this->assertContent($whenFalse, 'Noul "false" criterion');
        }
    }

    /**
     * @inheritDoc
     */
    public function toArray(): array
    {
        $data = ['type' => 'noul', 'instructions' => $this->instructions];
        $criteria = array_filter(
            ['true' => $this->whenTrue, 'false' => $this->whenFalse],
            static fn ($value) => $value !== null
        );
        if ($criteria !== []) {
            $data['criteria'] = $criteria;
        }

        return $data;
    }
}
