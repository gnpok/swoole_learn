<?php

declare(strict_types=1);

namespace SwooleLearn\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use SwooleLearn\Learning\Contracts\CoroutineRuntimeInterface;
use SwooleLearn\Learning\CoroutineBatchRunner;

final class CoroutineBatchRunnerTest extends TestCase
{
    public function test_it_runs_all_tasks_inside_coroutine_runtime(): void
    {
        $runtime = new FakeCoroutineRuntime();
        $runner = new CoroutineBatchRunner($runtime);

        $results = $runner->run([
            'profile' => static fn (): string => 'profile-ok',
            'orders' => static fn (): array => [1, 2, 3],
        ]);

        self::assertTrue($runtime->runCalled);
        self::assertSame(2, $runtime->goCalls);
        self::assertSame(
            [
                'profile' => 'profile-ok',
                'orders' => [1, 2, 3],
            ],
            $results
        );
    }

    public function test_it_rejects_non_callable_tasks(): void
    {
        $runtime = new FakeCoroutineRuntime();
        $runner = new CoroutineBatchRunner($runtime);

        $this->expectException(InvalidArgumentException::class);

        $runner->run([
            'bad-task' => 'not-callable',
        ]);
    }
}

final class FakeCoroutineRuntime implements CoroutineRuntimeInterface
{
    public bool $runCalled = false;

    public int $goCalls = 0;

    public function run(callable $callback): void
    {
        $this->runCalled = true;
        $callback();
    }

    public function go(callable $callback): void
    {
        $this->goCalls++;
        $callback();
    }
}
