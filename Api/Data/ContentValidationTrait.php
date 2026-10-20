<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypeSafeCore\Api\Data;

/**
 * Shared "non-empty trimmed string or non-empty array" check for value objects.
 *
 * @api
 */
trait ContentValidationTrait
{
    /**
     * Throw unless the value is a non-empty trimmed string or a non-empty array.
     *
     * @param mixed $value
     * @param string $label
     * @return void
     * @throws \InvalidArgumentException
     */
    private function assertContent(mixed $value, string $label): void
    {
        if (is_string($value) ? trim($value) === '' : (!is_array($value) || $value === [])) {
            throw new \InvalidArgumentException(
                sprintf('%s must be a non-empty string or a non-empty array.', $label)
            );
        }
    }
}
