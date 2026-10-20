<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

// phpcs:disable Magento2.Security.InsecureFunction, Generic.PHP.NoSilencedErrors -- test-only process control and port probing
namespace DmLab\TypeSafeCore\Test\Unit\Fixtures;

/**
 * PHP built-in server on 127.0.0.1 serving loopback-router.php.
 */
class LoopbackServer
{
    private const WORKERS = 8;
    private const READY_TIMEOUT = 5.0;

    /**
     * @var resource|null
     */
    private $process = null;

    /**
     * Start the server; returns its base URL, or null when it cannot start.
     *
     * @return string|null
     */
    public function start(): ?string
    {
        $port = self::freePort();
        if ($port === null) {
            return null;
        }
        $env = getenv();
        $env['PHP_CLI_SERVER_WORKERS'] = (string)self::WORKERS;
        $process = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . $port, __DIR__ . '/loopback-router.php'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
            null,
            $env
        );
        if (!is_resource($process)) {
            return null;
        }
        $this->process = $process;

        $deadline = microtime(true) + self::READY_TIMEOUT;
        while (microtime(true) < $deadline) {
            $socket = @stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $error, 0.2);
            if ($socket !== false) {
                fclose($socket);
                return 'http://127.0.0.1:' . $port;
            }
            usleep(50000);
        }
        $this->stop();

        return null;
    }

    /**
     * Stop the server.
     *
     * @return void
     */
    public function stop(): void
    {
        if ($this->process === null) {
            return;
        }
        proc_terminate($this->process);
        proc_close($this->process);
        $this->process = null;
    }

    /**
     * A port nothing listens on right now.
     *
     * @return int|null
     */
    public static function freePort(): ?int
    {
        $server = @stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        if ($server === false) {
            return null;
        }
        $name = (string)stream_socket_get_name($server, false);
        fclose($server);

        return (int)substr($name, strrpos($name, ':') + 1);
    }
}
