<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypeSafeCore\Exception;

/**
 * TypeSafe rejected the API key (HTTP 401/403); the status is the code.
 *
 * @api
 */
class AuthenticationException extends TypeSafeException
{
}
