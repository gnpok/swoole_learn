<?php

declare(strict_types=1);

namespace SwooleLearn\ShortUrl\Observability;

final class StdoutMetricsExporter
{
    /**
     * @param resource|null $stream
     */
    public function __construct(
        private readonly MetricsCollectorInterface $collector,
        private $stream = null
    ) {
        if (!is_resource($this->stream)) {
            $this->stream = fopen('php://stdout', 'wb');
        }
    }

    public function exportSnapshot(string $prefix = '# metrics_snapshot'): void
    {
        if (!is_resource($this->stream)) {
            return;
        }

        fwrite($this->stream, $prefix . PHP_EOL);
        fwrite($this->stream, $this->collector->renderPrometheus());
    }
}
