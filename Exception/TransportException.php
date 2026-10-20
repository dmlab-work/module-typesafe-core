<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypeSafeCore\Exception;

/**
 * Network/5xx failure after retries, or a malformed response.
 *
 * @api
 */
class TransportException extends TypeSafeException
{
}
