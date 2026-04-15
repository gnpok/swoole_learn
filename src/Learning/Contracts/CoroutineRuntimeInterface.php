<?php

declare(strict_types=1);

namespace SwooleLearn\Learning\Contracts;

interface CoroutineRuntimeInterface
{
    public function run(callable $callback): void;

    public function go(callable $callback): void;
}
