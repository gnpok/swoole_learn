<?php

declare(strict_types=1);

namespace SwooleLearn\Learning\Contracts;

interface WebSocketServerInterface
{
    public function set(array $settings): void;

    public function on(string $event, callable $handler): void;

    public function start(): void;

    public function push(int $fd, string $data): bool;

    public function disconnect(int $fd, int $code = 1000, string $reason = ''): bool;

    public function exists(int $fd): bool;
}
