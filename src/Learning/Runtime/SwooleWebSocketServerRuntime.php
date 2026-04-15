<?php

declare(strict_types=1);

namespace SwooleLearn\Learning\Runtime;

use RuntimeException;
use SwooleLearn\Learning\Contracts\WebSocketServerInterface;

final class SwooleWebSocketServerRuntime implements WebSocketServerInterface
{
    private object $server;

    public function __construct(string $host = '127.0.0.1', int $port = 9502)
    {
        if (!class_exists('\\Swoole\\WebSocket\\Server')) {
            throw new RuntimeException('Swoole WebSocket server runtime is not available. Install ext-swoole first.');
        }

        $this->server = new \Swoole\WebSocket\Server($host, $port);
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

    public function push(int $fd, string $data): bool
    {
        return (bool) $this->server->push($fd, $data);
    }

    public function disconnect(int $fd, int $code = 1000, string $reason = ''): bool
    {
        return (bool) $this->server->disconnect($fd, $code, $reason);
    }

    public function exists(int $fd): bool
    {
        return method_exists($this->server, 'isEstablished')
            ? (bool) $this->server->isEstablished($fd)
            : true;
    }
}
