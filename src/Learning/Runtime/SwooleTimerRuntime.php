<?php

declare(strict_types=1);

namespace SwooleLearn\Learning\Runtime;

use RuntimeException;
use SwooleLearn\Learning\Contracts\TimerRuntimeInterface;

final class SwooleTimerRuntime implements TimerRuntimeInterface
{
    public function tick(int $intervalMs, callable $callback): int
    {
        if (!class_exists('\\Swoole\\Timer')) {
            throw new RuntimeException('Swoole timer runtime is not available. Install ext-swoole first.');
        }

        return \Swoole\Timer::tick($intervalMs, $callback);
    }

    public function clear(int $timerId): void
    {
        if (!class_exists('\\Swoole\\Timer')) {
            throw new RuntimeException('Swoole timer runtime is not available. Install ext-swoole first.');
        }

        \Swoole\Timer::clear($timerId);
    }
}
