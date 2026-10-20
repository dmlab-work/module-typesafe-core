<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypeSafeCore\Api\Data\Question;

/**
 * A TypeSafe question, serialized into the `questions` map of the request body.
 *
 * @api
 */
interface QuestionInterface
{
    /**
     * Question payload as sent to TypeSafe.
     *
     * @return array
     */
    public function toArray(): array;
}
