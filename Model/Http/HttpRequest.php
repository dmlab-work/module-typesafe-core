<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypeSafeCore\Model\Http;

/**
 * Immutable outgoing HTTP request.
 *
 * @api
 */
class HttpRequest
{
    /**
     * @param string $method
     * @param string $url
     * @param array<string,string> $headers name => value
     * @param string|null $body
     * @param int $timeout seconds
     */
    public function __construct(
        private readonly string $method,
        private readonly string $url,
        #[\SensitiveParameter] private readonly array $headers = [],
        private readonly ?string $body = null,
        private readonly int $timeout = 30
    ) {
    }

    /**
     * HTTP method.
     *
     * @return string
     */
    public function getMethod(): string
    {
        return $this->method;
    }

    /**
     * Absolute URL.
     *
     * @return string
     */
    public function getUrl(): string
    {
        return $this->url;
    }

    /**
     * Headers, name => value.
     *
     * @return array<string,string>
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    /**
     * Request body, null for none.
     *
     * @return string|null
     */
    public function getBody(): ?string
    {
        return $this->body;
    }

    /**
     * Total timeout in seconds.
     *
     * @return int
     */
    public function getTimeout(): int
    {
        return $this->timeout;
    }
}
