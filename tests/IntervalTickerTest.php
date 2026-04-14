<?php

declare(strict_types=1);

namespace SwooleLearn\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use SwooleLearn\Learning\Contracts\TimerRuntimeInterface;
use SwooleLearn\Learning\IntervalTicker;

final class IntervalTickerTest extends TestCase
{
    public function test_it_clears_timer_after_max_ticks(): void
    {
        $runtime = new FakeTimerRuntime();
        $ticker = new IntervalTicker($runtime);

        $ticks = [];
        $timerId = $ticker->schedule(100, 3, static function (int $tick) use (&$ticks): void {
            $ticks[] = $tick;
        });

        $runtime->trigger($timerId, 5);

        self::assertSame([1, 2, 3], $ticks);
        self::assertSame([$timerId], $runtime->clearedIds);
    }

    public function test_it_validates_positive_interval_and_max_ticks(): void
    {
        $runtime = new FakeTimerRuntime();
        $ticker = new IntervalTicker($runtime);

        $this->expectException(InvalidArgumentException::class);
        $ticker->schedule(0, 1, static fn (): null => null);
    }

    public function test_it_validates_positive_max_ticks(): void
    {
        $runtime = new FakeTimerRuntime();
        $ticker = new IntervalTicker($runtime);

        $this->expectException(InvalidArgumentException::class);
        $ticker->schedule(10, 0, static fn (): null => null);
    }
}

final class FakeTimerRuntime implements TimerRuntimeInterface
{
    /** @var array<int, callable> */
    private array $callbacks = [];

    /** @var array<int, true> */
    private array $cleared = [];

    /** @var list<int> */
    public array $clearedIds = [];

    private int $nextId = 1;

    public function tick(int $intervalMs, callable $callback): int
    {
        $timerId = $this->nextId++;
        $this->callbacks[$timerId] = $callback;

        return $timerId;
    }

    public function clear(int $timerId): void
    {
        $this->cleared[$timerId] = true;
        $this->clearedIds[] = $timerId;
    }

    public function trigger(int $timerId, int $times): void
    {
        if (!isset($this->callbacks[$timerId])) {
            return;
        }

        for ($i = 0; $i < $times; $i++) {
            if (isset($this->cleared[$timerId])) {
                break;
            }

            $callback = $this->callbacks[$timerId];
            $callback();
        }
    }
}
