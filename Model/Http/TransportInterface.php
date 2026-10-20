<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypeSafeCore\Model\Http;

/**
 * Sends HTTP requests in parallel.
 */
interface TransportInterface
{
    /**
     * Send all requests; a single failure is a transport-error response, never a throw.
     *
     * @param array<int|string,HttpRequest> $requests
     * @return array<int|string,HttpResponse> same keys as $requests
     */
    public function sendMany(array $requests): array;
}
