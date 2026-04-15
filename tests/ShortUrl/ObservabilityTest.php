<?php

declare(strict_types=1);

namespace SwooleLearn\Tests\ShortUrl;

use PHPUnit\Framework\TestCase;
use SwooleLearn\ShortUrl\Observability\CallableHealthReporter;
use SwooleLearn\ShortUrl\Observability\InMemoryPrometheusCollector;
use SwooleLearn\ShortUrl\Observability\StdoutMetricsExporter;

final class ObservabilityTest extends TestCase
{
    public function test_prometheus_collector_renders_histogram_monotonic_buckets(): void
    {
        $collector = new InMemoryPrometheusCollector();
        $collector->observeHistogram(
            name: 'shorturl_http_request_duration_seconds',
            value: 0.02,
            labels: ['route' => 'create', 'method' => 'POST']
        );
        $collector->observeHistogram(
            name: 'shorturl_http_request_duration_seconds',
            value: 0.8,
            labels: ['route' => 'create', 'method' => 'POST']
        );

        $dump = $collector->renderPrometheus();
        self::assertStringContainsString('shorturl_http_request_duration_seconds_bucket{method="POST",route="create",le="0.05"} 1', $dump);
        self::assertStringContainsString('shorturl_http_request_duration_seconds_bucket{method="POST",route="create",le="1"} 2', $dump);
        self::assertStringContainsString('shorturl_http_request_duration_seconds_bucket{method="POST",route="create",le="+Inf"} 2', $dump);
        self::assertStringContainsString('shorturl_http_request_duration_seconds_count{method="POST",route="create"} 2', $dump);
    }

    public function test_health_reporter_returns_degraded_when_non_binary_check_status(): void
    {
        $reporter = new CallableHealthReporter([
            'mysql' => static fn (): array => ['status' => 'up'],
            'redis' => static fn (): array => ['status' => 'degraded', 'details' => ['replication_lag_ms' => 120]],
        ]);

        $payload = $reporter->report();
        self::assertSame('degraded', $payload['status']);
        self::assertSame('degraded', $payload['checks']['redis']['status'] ?? null);
    }

    public function test_stdout_metrics_exporter_writes_prometheus_snapshot(): void
    {
        $collector = new InMemoryPrometheusCollector();
        $collector->incrementCounter('shorturl_http_requests_total', labels: ['method' => 'GET', 'route' => 'metrics', 'status_code' => '200']);

        $stream = fopen('php://temp', 'w+');
        self::assertIsResource($stream);
        $exporter = new StdoutMetricsExporter($collector, $stream);
        $exporter->exportSnapshot('# unit_test_snapshot');
        rewind($stream);
        $contents = stream_get_contents($stream);
        fclose($stream);

        self::assertIsString($contents);
        self::assertStringContainsString('# unit_test_snapshot', $contents);
        self::assertStringContainsString('shorturl_http_requests_total{method="GET",route="metrics",status_code="200"} 1', $contents);
    }

    public function test_trace_context_extracts_trace_id_from_valid_traceparent(): void
    {
        $traceId = \SwooleLearn\ShortUrl\Http\TraceContext::extractTraceIdFromTraceparent(
            '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01'
        );
        self::assertSame('4bf92f3577b34da6a3ce929d0e0e4736', $traceId);
    }

    public function test_trace_context_returns_null_for_invalid_traceparent(): void
    {
        $traceId = \SwooleLearn\ShortUrl\Http\TraceContext::extractTraceIdFromTraceparent('invalid-header');
        self::assertNull($traceId);
    }
}
