<?php

declare(strict_types=1);

namespace SwooleLearn\Learning\Runtime;

use RuntimeException;
use SwooleLearn\Learning\Contracts\CoroutineRuntimeInterface;

final class SwooleCoroutineRuntime implements CoroutineRuntimeInterface
{
    public function run(callable $callback): void
    {
        if (!function_exists('\\Swoole\\Coroutine\\run')) {
            throw new RuntimeException('Swoole coroutine runtime is not available. Install ext-swoole first.');
        }

        \Swoole\Coroutine\run($callback);
    }

    public function go(callable $callback): void
    {
        if (!class_exists('\\Swoole\\Coroutine')) {
            throw new RuntimeException('Swoole coroutine runtime is not available. Install ext-swoole first.');
        }

        \Swoole\Coroutine::create($callback);
    }
}
