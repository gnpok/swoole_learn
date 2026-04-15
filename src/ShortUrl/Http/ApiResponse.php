<?php

declare(strict_types=1);

namespace SwooleLearn\ShortUrl\Http;

final class ApiResponse
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        public readonly int $statusCode,
        public readonly string $body,
        public readonly array $headers = ['Content-Type' => 'application/json; charset=utf-8']
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function json(int $statusCode, array $payload): self
    {
        return new self(
            $statusCode,
            (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }

    public static function redirect(string $location, int $statusCode = 302): self
    {
        return new self($statusCode, '', ['Location' => $location]);
    }

    public static function noContent(): self
    {
        return new self(204, '', []);
    }

    /**
     * @param array<string, string> $headers
     */
    public static function text(int $statusCode, string $body, array $headers = []): self
    {
        $merged = ['Content-Type' => 'text/plain; charset=utf-8'];
        foreach ($headers as $key => $value) {
            $merged[$key] = $value;
        }

        return new self($statusCode, $body, $merged);
    }
}
