<?php

declare(strict_types=1);

namespace SwooleLearn\Learning\Contracts;

interface HttpServerInterface
{
    public function set(array $settings): void;

    public function on(string $event, callable $handler): void;

    public function start(): void;
}
