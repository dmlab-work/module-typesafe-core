<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypeSafeCore\Exception;

/**
 * The model output is unparseable or violates the answer schema; retried once with a repair note.
 *
 * @api
 */
class InvalidAnswerException extends TransportException
{
}
