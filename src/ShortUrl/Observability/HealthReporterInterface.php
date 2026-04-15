<?php

declare(strict_types=1);

namespace SwooleLearn\ShortUrl\Observability;

interface HealthReporterInterface
{
    /**
     * @return array{
     *   status: string,
     *   timestamp: string,
     *   checks: array<string, array{
     *     status: string,
     *     latency_ms: float,
     *     details?: array<string, mixed>,
     *     error?: string
     *   }>
     * }
     */
    public function report(): array;
}
