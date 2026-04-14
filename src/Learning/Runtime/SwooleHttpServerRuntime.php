<?php

declare(strict_types=1);

namespace SwooleLearn\Learning\Runtime;

use RuntimeException;
use SwooleLearn\Learning\Contracts\HttpServerInterface;

final class SwooleHttpServerRuntime implements HttpServerInterface
{
    private object $server;

    public function __construct(string $host = '127.0.0.1', int $port = 9501)
    {
        if (!class_exists('\\Swoole\\Http\\Server')) {
            throw new RuntimeException('Swoole HTTP server runtime is not available. Install ext-swoole first.');
        }

        $this->server = new \Swoole\Http\Server($host, $port);
    }

    public function set(array $settings): void
    {
        $this->server->set($settings);
    }

    public function on(string $event, callable $handler): void
    {
        $this->server->on($event, $handler);
    }

    public function start(): void
    {
        $this->server->start();
    }
}
