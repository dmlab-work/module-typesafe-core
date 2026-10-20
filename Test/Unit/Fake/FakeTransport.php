<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypeSafeCore\Test\Unit\Fake;

use DmLab\TypeSafeCore\Model\Http\HttpRequest;
use DmLab\TypeSafeCore\Model\Http\HttpResponse;
use DmLab\TypeSafeCore\Model\Http\TransportInterface;

/**
 * Scripted transport: answers from a handler or a FIFO queue, records every call.
 */
class FakeTransport implements TransportInterface
{
    /** @var array<int, array<int|string, HttpRequest>> one entry per sendMany() call */
    public array $calls = [];

    /** @var HttpResponse[] */
    public array $queue = [];

    /** @var (\Closure(HttpRequest, int|string): HttpResponse)|null */
    public ?\Closure $handler = null;

    /**
     * @param HttpResponse ...$responses queued in order
     */
    public function __construct(HttpResponse ...$responses)
    {
        $this->queue = $responses;
    }

    /**
     * @inheritDoc
     */
    public function sendMany(array $requests): array
    {
        $this->calls[] = $requests;
        $responses = [];
        foreach ($requests as $key => $request) {
            if ($this->handler !== null) {
                $responses[$key] = ($this->handler)($request, $key);
                continue;
            }
            if ($this->queue === []) {
                throw new \LogicException('FakeTransport has no scripted response left.');
            }
            $responses[$key] = array_shift($this->queue);
        }

        return $responses;
    }

    /**
     * Every request sent, flattened in send order.
     *
     * @return HttpRequest[]
     */
    public function sent(): array
    {
        return array_merge(...array_map('array_values', $this->calls));
    }
}
