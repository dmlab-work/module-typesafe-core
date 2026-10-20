<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypeSafeCore\Exception;

use Magento\Framework\Exception\LocalizedException;

/**
 * Base of every TypeSafe client error.
 *
 * @api
 */
class TypeSafeException extends LocalizedException
{
}
