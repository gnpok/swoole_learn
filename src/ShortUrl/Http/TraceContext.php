<?php

declare(strict_types=1);

namespace SwooleLearn\ShortUrl\Http;

final class TraceContext
{
    /**
     * @param array<string, string> $headers
     *
     * @return array{trace_id: string, source: string}
     */
    public static function resolve(array $headers): array
    {
        $traceparent = $headers['traceparent'] ?? null;
        if (is_string($traceparent) && $traceparent !== '') {
            $parsed = self::extractTraceIdFromTraceparent($traceparent);
            if ($parsed !== null) {
                return ['trace_id' => $parsed, 'source' => 'traceparent'];
            }
        }

        $xTraceId = $headers['x-trace-id'] ?? $headers['X-Trace-Id'] ?? null;
        if (is_string($xTraceId) && trim($xTraceId) !== '') {
            return ['trace_id' => trim($xTraceId), 'source' => 'x-trace-id'];
        }

        return ['trace_id' => self::newTraceId(), 'source' => 'generated'];
    }

    public static function newTraceId(): string
    {
        try {
            return bin2hex(random_bytes(8));
        } catch (\Throwable) {
            return str_replace('.', '', uniqid('trace', true));
        }
    }

    public static function extractTraceIdFromTraceparent(string $traceparent): ?string
    {
        $value = trim($traceparent);
        if ($value === '') {
            return null;
        }

        if (preg_match('/^[\da-f]{2}-([\da-f]{32})-[\da-f]{16}-[\da-f]{2}$/i', $value, $matches) !== 1) {
            return null;
        }

        return strtolower($matches[1]);
    }
}
