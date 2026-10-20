<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

// phpcs:disable Magento2.Functions.DiscouragedFunction -- raw curl_multi is required: Magento's Curl client drops status and headers
namespace DmLab\TypeSafeCore\Model\Http;

/**
 * Parallel transport over raw curl_multi.
 */
class CurlMultiTransport implements TransportInterface
{
    private const MAX_CONNECT_TIMEOUT = 10;

    /**
     * @param int $allowedProtocols CURLPROTO_* bitmask for requests and redirects
     */
    public function __construct(
        private readonly int $allowedProtocols = CURLPROTO_HTTPS
    ) {
    }

    /**
     * @inheritdoc
     */
    public function sendMany(array $requests): array
    {
        if ($requests === []) {
            return [];
        }

        $handles = [];
        $headers = [];
        foreach ($requests as $key => $request) {
            $headers[$key] = [];
            $handles[$key] = $this->createHandle($request, $headers[$key]);
        }

        $codes = $this->run($handles);

        $responses = [];
        foreach ($handles as $key => $handle) {
            $errno = $codes[spl_object_id($handle)] ?? curl_errno($handle);
            $responses[$key] = new HttpResponse(
                $errno ? 0 : (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE),
                $headers[$key],
                (string)curl_multi_getcontent($handle),
                $errno,
                $errno ? (string)curl_strerror($errno) : ''
            );
        }

        return $responses;
    }

    /**
     * Build a configured easy handle; response headers are collected into $headers.
     *
     * @param HttpRequest $request
     * @param array $headers
     * @return \CurlHandle
     */
    private function createHandle(HttpRequest $request, array &$headers): \CurlHandle
    {
        $handle = curl_init();
        $options = [
            CURLOPT_URL => $request->getUrl(),
            CURLOPT_CUSTOMREQUEST => $request->getMethod(),
            CURLOPT_HTTPHEADER => $this->headerLines($request->getHeaders()),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => $this->allowedProtocols,
            CURLOPT_REDIR_PROTOCOLS => $this->allowedProtocols,
            CURLOPT_TIMEOUT => $request->getTimeout(),
            CURLOPT_CONNECTTIMEOUT => min($request->getTimeout(), self::MAX_CONNECT_TIMEOUT),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_ENCODING => '',
            CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$headers): int {
                $trimmed = trim($line);
                if (str_starts_with($trimmed, 'HTTP/')) {
                    $headers = [];
                } elseif (str_contains($trimmed, ':')) {
                    [$name, $value] = explode(':', $trimmed, 2);
                    $headers[strtolower(trim($name))] = trim($value);
                }

                return strlen($line);
            },
        ];
        if ($request->getBody() !== null) {
            $options[CURLOPT_POSTFIELDS] = $request->getBody();
        }
        curl_setopt_array($handle, $options);

        return $handle;
    }

    /**
     * Run all handles to completion; returns curl result codes keyed by handle object id.
     *
     * @param \CurlHandle[] $handles
     * @return array<int,int>
     */
    private function run(array $handles): array
    {
        $multi = curl_multi_init();
        foreach ($handles as $handle) {
            curl_multi_add_handle($multi, $handle);
        }

        $codes = [];
        $running = 0;
        do {
            $status = curl_multi_exec($multi, $running);
            if ($running && curl_multi_select($multi, 1.0) === -1) {
                usleep(1000);
            }
            while ($info = curl_multi_info_read($multi)) {
                $codes[spl_object_id($info['handle'])] = $info['result'];
            }
        } while ($running && $status === CURLM_OK);

        foreach ($handles as $handle) {
            curl_multi_remove_handle($multi, $handle);
        }
        curl_multi_close($multi);

        return $codes;
    }

    /**
     * Format headers as curl lines; an empty value suppresses curl's default header.
     *
     * @param array<string,string> $headers
     * @return string[]
     */
    private function headerLines(#[\SensitiveParameter] array $headers): array
    {
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = $value === '' ? $name . ':' : $name . ': ' . $value;
        }

        return $lines;
    }
}
