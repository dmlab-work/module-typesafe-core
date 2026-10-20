<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypeSafeCore\Exception;

/**
 * TypeSafe rejected the request (other 4xx, status as code) or it could not be encoded locally.
 *
 * @api
 */
class RequestException extends TypeSafeException
{
}
