<?php

declare(strict_types=1);

namespace SwooleLearn\ShortUrl\Observability;

use function ksort;

final class InMemoryPrometheusCollector implements MetricsCollectorInterface
{
    /** @var array<string, string> */
    private array $help = [];

    /** @var array<string, array<string, float>> */
    private array $counters = [];

    /** @var array<string, array<string, float>> */
    private array $gauges = [];

    /** @var array<string, list<float>> */
    private array $histogramBuckets = [];

    /**
     * @var array<string, array<string, array{
     *   labels: array<string, string>,
     *   buckets: array<float, int>,
     *   count: int,
     *   sum: float
     * }>>
     */
    private array $histograms = [];

    public function incrementCounter(string $name, float $value = 1.0, array $labels = [], string $help = ''): void
    {
        $this->help[$name] = $help !== '' ? $help : ($this->help[$name] ?? '');
        $key = $this->sampleKey($labels);
        $this->counters[$name][$key] = ($this->counters[$name][$key] ?? 0.0) + $value;
    }

    public function setGauge(string $name, float $value, array $labels = [], string $help = ''): void
    {
        $this->help[$name] = $help !== '' ? $help : ($this->help[$name] ?? '');
        $key = $this->sampleKey($labels);
        $this->gauges[$name][$key] = $value;
    }

    public function observeHistogram(
        string $name,
        float $value,
        array $labels = [],
        array $buckets = [],
        string $help = ''
    ): void {
        $this->help[$name] = $help !== '' ? $help : ($this->help[$name] ?? '');
        $normalizedBuckets = $buckets !== [] ? $buckets : [0.005, 0.01, 0.025, 0.05, 0.1, 0.3, 1, 3, 5, 10];
        sort($normalizedBuckets);
        $this->histogramBuckets[$name] = $normalizedBuckets;

        $key = $this->sampleKey($labels);
        if (!isset($this->histograms[$name][$key])) {
            $bucketMap = [];
            foreach ($normalizedBuckets as $bucket) {
                $bucketMap[(float) $bucket] = 0;
            }

            $this->histograms[$name][$key] = [
                'labels' => $this->normalizeLabels($labels),
                'buckets' => $bucketMap,
                'count' => 0,
                'sum' => 0.0,
            ];
        }

        $sample = &$this->histograms[$name][$key];
        $sample['count']++;
        $sample['sum'] += $value;
        foreach ($sample['buckets'] as $bucket => $count) {
            if ($value <= (float) $bucket) {
                $sample['buckets'][$bucket] = $count + 1;
            }
        }
    }

    public function renderPrometheus(): string
    {
        $lines = [];

        foreach ($this->counters as $name => $samples) {
            $lines[] = sprintf('# HELP %s %s', $name, $this->escapeHelp($this->help[$name] ?? ''));
            $lines[] = sprintf('# TYPE %s counter', $name);
            foreach ($samples as $key => $value) {
                $lines[] = sprintf('%s%s %s', $name, $this->renderLabels($key), $this->renderFloat($value));
            }
        }

        foreach ($this->gauges as $name => $samples) {
            $lines[] = sprintf('# HELP %s %s', $name, $this->escapeHelp($this->help[$name] ?? ''));
            $lines[] = sprintf('# TYPE %s gauge', $name);
            foreach ($samples as $key => $value) {
                $lines[] = sprintf('%s%s %s', $name, $this->renderLabels($key), $this->renderFloat($value));
            }
        }

        foreach ($this->histograms as $name => $samples) {
            $lines[] = sprintf('# HELP %s %s', $name, $this->escapeHelp($this->help[$name] ?? ''));
            $lines[] = sprintf('# TYPE %s histogram', $name);
            foreach ($samples as $sample) {
                $labels = $sample['labels'];
                $running = 0;
                foreach ($sample['buckets'] as $bucket => $count) {
                    $lines[] = sprintf(
                        '%s_bucket%s %d',
                        $name,
                        $this->renderLabelsFromArray($labels + ['le' => (string) $bucket]),
                        $count
                    );
                }
                $lines[] = sprintf(
                    '%s_bucket%s %d',
                    $name,
                    $this->renderLabelsFromArray($labels + ['le' => '+Inf']),
                    $sample['count']
                );
                $lines[] = sprintf('%s_sum%s %s', $name, $this->renderLabelsFromArray($labels), $this->renderFloat($sample['sum']));
                $lines[] = sprintf('%s_count%s %d', $name, $this->renderLabelsFromArray($labels), $sample['count']);
            }
        }

        if ($lines === []) {
            return '';
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * @param array<string, string> $labels
     */
    private function sampleKey(array $labels): string
    {
        $normalized = $this->normalizeLabels($labels);
        if ($normalized === []) {
            return '';
        }

        return (string) json_encode($normalized);
    }

    /**
     * @param array<string, string> $labels
     *
     * @return array<string, string>
     */
    private function normalizeLabels(array $labels): array
    {
        $normalized = [];
        foreach ($labels as $key => $value) {
            $normalized[(string) $key] = (string) $value;
        }
        ksort($normalized);

        return $normalized;
    }

    private function renderLabels(string $key): string
    {
        if ($key === '') {
            return '';
        }

        $labels = json_decode($key, true);
        if (!is_array($labels)) {
            return '';
        }

        /** @var array<string, string> $labels */
        return $this->renderLabelsFromArray($labels);
    }

    /**
     * @param array<string, string> $labels
     */
    private function renderLabelsFromArray(array $labels): string
    {
        if ($labels === []) {
            return '';
        }

        $parts = [];
        foreach ($labels as $key => $value) {
            $parts[] = sprintf('%s="%s"', $key, $this->escapeLabelValue($value));
        }

        return '{' . implode(',', $parts) . '}';
    }

    private function escapeLabelValue(string $value): string
    {
        return str_replace(["\\", "\"", "\n"], ["\\\\", "\\\"", "\\n"], $value);
    }

    private function escapeHelp(string $help): string
    {
        if ($help === '') {
            return '';
        }

        return str_replace(["\\", "\n"], ["\\\\", "\\n"], $help);
    }

    private function renderFloat(float $value): string
    {
        return rtrim(rtrim(sprintf('%.12F', $value), '0'), '.');
    }
}
