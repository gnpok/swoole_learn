<?php

declare(strict_types=1);

namespace SwooleLearn\ShortUrl\Observability;

use DateTimeImmutable;
use Throwable;

final class CallableHealthReporter implements HealthReporterInterface
{
    /**
     * @param array<string, callable(): array{status: string, details?: array<string, mixed>}> $checks
     */
    public function __construct(private readonly array $checks)
    {
    }

    public function report(): array
    {
        $checkPayload = [];
        $overall = 'up';

        foreach ($this->checks as $name => $check) {
            $start = hrtime(true);
            try {
                $result = $check();
                $status = (string) ($result['status'] ?? 'down');
                $latencyMs = (hrtime(true) - $start) / 1_000_000;
                $entry = [
                    'status' => $status,
                    'latency_ms' => $latencyMs,
                ];
                if (isset($result['details']) && is_array($result['details'])) {
                    $entry['details'] = $result['details'];
                }
                $checkPayload[$name] = $entry;

                if ($status === 'down') {
                    $overall = 'down';
                } elseif ($status !== 'up' && $overall !== 'down') {
                    $overall = 'degraded';
                }
            } catch (Throwable $throwable) {
                $latencyMs = (hrtime(true) - $start) / 1_000_000;
                $checkPayload[$name] = [
                    'status' => 'down',
                    'latency_ms' => $latencyMs,
                    'error' => $throwable->getMessage(),
                ];
                $overall = 'down';
            }
        }

        return [
            'status' => $overall,
            'timestamp' => (new DateTimeImmutable())->format(DATE_ATOM),
            'checks' => $checkPayload,
        ];
    }
}
