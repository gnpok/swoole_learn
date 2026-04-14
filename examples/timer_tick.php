<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use SwooleLearn\Learning\IntervalTicker;
use SwooleLearn\Learning\Runtime\SwooleTimerRuntime;

$ticker = new IntervalTicker(new SwooleTimerRuntime());
$ticker->schedule(500, 5, static function (int $tick): void {
    echo sprintf("tick #%d at %s\n", $tick, date(DATE_ATOM));
});

\Swoole\Event::wait();
