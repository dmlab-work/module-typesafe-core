<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypeSafeCore\Api;

/**
 * Evaluates requests against the TypeSafe API (cached, budgeted, retried).
 *
 * @api
 */
interface ClientInterface
{
    /**
     * Evaluate one request.
     *
     * @param Data\Request $request
     * @return Data\Response
     * @throws \DmLab\TypeSafeCore\Exception\TypeSafeException
     */
    public function evaluate(Data\Request $request): Data\Response;

    /**
     * Evaluate many requests in parallel; each key gets a response or an exception, never a throw.
     *
     * @param Data\Request[] $requests keyed by string|int
     * @return Data\Response[]|\DmLab\TypeSafeCore\Exception\TypeSafeException[] same keys as $requests
     */
    public function evaluateMany(array $requests): array;
}
