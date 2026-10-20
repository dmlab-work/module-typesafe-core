<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypeSafeCore\Exception;

use Magento\Framework\Phrase;

/**
 * HTTP 429 after retries, or refused without HTTP while a remembered one lasts; the code is 429.
 *
 * @api
 */
class RateLimitException extends TypeSafeException
{
    /**
     * @param Phrase $phrase
     * @param \Exception|null $cause
     * @param int $code
     * @param int|null $retryAfter
     */
    public function __construct(
        Phrase $phrase,
        ?\Exception $cause = null,
        int $code = 0,
        private readonly ?int $retryAfter = null
    ) {
        parent::__construct($phrase, $cause, $code);
    }

    /**
     * Seconds to wait (server retry-after or the remaining pause); null when the header was absent.
     *
     * @return int|null
     */
    public function getRetryAfter(): ?int
    {
        return $this->retryAfter;
    }
}
