<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypeSafeCore\Model\Http;

/**
 * Immutable HTTP result; status 0 means no response was received.
 */
class HttpResponse
{
    /**
     * @var array<string,string>
     */
    private readonly array $headers;

    /**
     * @param int $status
     * @param array<string,string> $headers name => last value
     * @param string $body
     * @param int $errno curl error code, 0 for none
     * @param string $error
     */
    public function __construct(
        private readonly int $status,
        array $headers = [],
        private readonly string $body = '',
        private readonly int $errno = 0,
        private readonly string $error = ''
    ) {
        $this->headers = array_change_key_case($headers, CASE_LOWER);
    }

    /**
     * HTTP status code, 0 when no response was received.
     *
     * @return int
     */
    public function getStatus(): int
    {
        return $this->status;
    }

    /**
     * Headers keyed by lowercased name.
     *
     * @return array<string,string>
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    /**
     * Header value by case-insensitive name.
     *
     * @param string $name
     * @return string|null
     */
    public function getHeader(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /**
     * Response body.
     *
     * @return string
     */
    public function getBody(): string
    {
        return $this->body;
    }

    /**
     * Curl error code, 0 for none.
     *
     * @return int
     */
    public function getErrno(): int
    {
        return $this->errno;
    }

    /**
     * Curl error message, empty for none.
     *
     * @return string
     */
    public function getError(): string
    {
        return $this->error;
    }

    /**
     * True when no HTTP response was received.
     *
     * @return bool
     */
    public function isTransportError(): bool
    {
        return $this->errno !== 0 || $this->status === 0;
    }
}
