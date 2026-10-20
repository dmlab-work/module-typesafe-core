<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypeSafeCore\Api\Data\Answer;

/**
 * Typed answer to one question.
 *
 * @api
 */
interface AnswerInterface
{
    /**
     * Question type this answers: noul, choice or score.
     *
     * @return string
     */
    public function getType(): string;
}
