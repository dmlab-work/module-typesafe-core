<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypeSafeCore\Exception;

/**
 * Calls are paused after repeated transport/5xx failures; nothing was sent.
 *
 * @api
 */
class CircuitOpenException extends TypeSafeException
{
}
