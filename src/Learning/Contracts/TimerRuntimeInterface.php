<?php

declare(strict_types=1);

namespace SwooleLearn\Learning\Contracts;

interface TimerRuntimeInterface
{
    public function tick(int $intervalMs, callable $callback): int;

    public function clear(int $timerId): void;
}
