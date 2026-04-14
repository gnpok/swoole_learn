<?php

declare(strict_types=1);

namespace SwooleLearn\Learning;

use InvalidArgumentException;
use SwooleLearn\Learning\Contracts\TimerRuntimeInterface;

final class IntervalTicker
{
    public function __construct(private readonly TimerRuntimeInterface $runtime)
    {
    }

    public function schedule(int $intervalMs, int $maxTicks, callable $onTick): int
    {
        if ($intervalMs <= 0) {
            throw new InvalidArgumentException('Interval must be greater than 0.');
        }

        if ($maxTicks <= 0) {
            throw new InvalidArgumentException('Max ticks must be greater than 0.');
        }

        $counter = 0;
        $timerId = 0;

        $timerId = $this->runtime->tick($intervalMs, function () use (&$counter, $maxTicks, $onTick, &$timerId): void {
            if ($counter >= $maxTicks) {
                return;
            }

            $counter++;
            $onTick($counter);

            if ($counter >= $maxTicks) {
                $this->runtime->clear($timerId);
            }
        });

        return $timerId;
    }
}
