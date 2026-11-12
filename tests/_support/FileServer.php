<?php

namespace simplygoodwork\craftportage\tests\support;

use RuntimeException;

/**
 * A local file server, PHP's built-in one, serving the fixture files in `tests/_data/files` through the router in
 * `tests/_data/file-server.php`. Tests fetch assets from it rather than from the internet.
 */
final class FileServer
{
    /**
     * @var resource|null
     */
    private static mixed $process = null;

    private static int $port = 0;

    /**
     * Starts the server, once per run, and returns its base URL, like `http://127.0.0.1:8123`.
     */
    public static function start(): string
    {
        if (self::$process === null) {
            self::$port = self::freePort();
            $command = [PHP_BINARY, '-S', '127.0.0.1:' . self::$port, dirname(__DIR__) . '/_data/file-server.php'];
            $process = proc_open($command, [['pipe', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']], $pipes);
            if (!is_resource($process)) {
                throw new RuntimeException('Couldn\'t start the file server.');
            }
            self::$process = $process;
            register_shutdown_function([self::class, 'stop']);
            self::waitUntilListening();
        }

        return 'http://127.0.0.1:' . self::$port;
    }

    public static function stop(): void
    {
        if (self::$process !== null) {
            proc_terminate(self::$process);
            proc_close(self::$process);
            self::$process = null;
        }
    }

    private static function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        if ($socket === false) {
            throw new RuntimeException('Couldn\'t find a free port for the file server.');
        }
        $name = (string)stream_socket_get_name($socket, false);
        fclose($socket);

        return (int)substr($name, strrpos($name, ':') + 1);
    }

    private static function waitUntilListening(): void
    {
        for ($i = 0; $i < 50; $i++) {
            $connection = @fsockopen('127.0.0.1', self::$port, $errno, $error, 0.1);
            if ($connection !== false) {
                fclose($connection);
                return;
            }
            usleep(100_000);
        }

        throw new RuntimeException('The file server didn\'t start listening on port ' . self::$port . '.');
    }
}
