<?php

declare(strict_types=1);

namespace SwooleLearn\Learning;

use InvalidArgumentException;
use SwooleLearn\Learning\Contracts\CoroutineRuntimeInterface;

final class CoroutineBatchRunner
{
    public function __construct(private readonly CoroutineRuntimeInterface $runtime)
    {
    }

    /**
     * @param array<string, callable> $tasks
     *
     * @return array<string, mixed>
     */
    public function run(array $tasks): array
    {
        $results = [];

        $this->runtime->run(function () use (&$results, $tasks): void {
            foreach ($tasks as $name => $task) {
                if (!is_callable($task)) {
                    throw new InvalidArgumentException(sprintf('Task "%s" must be callable.', (string) $name));
                }

                $this->runtime->go(function () use (&$results, $name, $task): void {
                    $results[(string) $name] = $task();
                });
            }
        });

        return $results;
    }
}
