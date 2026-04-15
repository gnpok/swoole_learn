<?php

declare(strict_types=1);

namespace SwooleLearn\ShortUrl\Observability;

interface MetricsCollectorInterface
{
    /**
     * @param array<string, string> $labels
     */
    public function incrementCounter(string $name, float $value = 1.0, array $labels = [], string $help = ''): void;

    /**
     * @param array<string, string> $labels
     */
    public function setGauge(string $name, float $value, array $labels = [], string $help = ''): void;

    /**
     * @param array<string, string> $labels
     * @param list<float> $buckets
     */
    public function observeHistogram(
        string $name,
        float $value,
        array $labels = [],
        array $buckets = [],
        string $help = ''
    ): void;

    public function renderPrometheus(): string;
}
