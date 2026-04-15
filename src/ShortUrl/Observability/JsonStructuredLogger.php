<?php

declare(strict_types=1);

namespace SwooleLearn\ShortUrl\Observability;

use DateTimeImmutable;

final class JsonStructuredLogger implements LoggerInterface
{
    /**
     * @param resource|null $stream
     */
    public function __construct(private $stream = null)
    {
        if (!is_resource($this->stream)) {
            $this->stream = fopen('php://stdout', 'wb');
        }
    }

    public function info(string $message, array $context = []): void
    {
        $this->write('INFO', $message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->write('WARNING', $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->write('ERROR', $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function write(string $level, string $message, array $context): void
    {
        $payload = [
            'timestamp' => (new DateTimeImmutable())->format(DATE_ATOM),
            'level' => $level,
            'message' => $message,
            'context' => $context,
        ];

        if (!is_resource($this->stream)) {
            return;
        }

        fwrite($this->stream, (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL);
    }
}
