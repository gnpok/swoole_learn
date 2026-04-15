<?php

declare(strict_types=1);

namespace SwooleLearn\ShortUrl\Observability;

final class NullMetricsCollector implements MetricsCollectorInterface
{
    public function incrementCounter(string $name, float $value = 1.0, array $labels = [], string $help = ''): void
    {
    }

    public function setGauge(string $name, float $value, array $labels = [], string $help = ''): void
    {
    }

    public function observeHistogram(
        string $name,
        float $value,
        array $labels = [],
        array $buckets = [],
        string $help = ''
    ): void {
    }

    public function renderPrometheus(): string
    {
        return '';
    }
}
