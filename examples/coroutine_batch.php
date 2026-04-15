<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use SwooleLearn\Learning\CoroutineBatchRunner;
use SwooleLearn\Learning\Runtime\SwooleCoroutineRuntime;

$runner = new CoroutineBatchRunner(new SwooleCoroutineRuntime());

$results = $runner->run([
    'task-1' => static function (): string {
        \Swoole\Coroutine::sleep(0.1);

        return 'done-1';
    },
    'task-2' => static function (): string {
        \Swoole\Coroutine::sleep(0.05);

        return 'done-2';
    },
]);

var_export($results);
echo PHP_EOL;
