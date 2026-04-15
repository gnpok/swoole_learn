<?php

declare(strict_types=1);

namespace SwooleLearn\ShortUrl\Http;

final class RequestContext
{
    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed>|null $body
     * @param array<string, string> $headers
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query = [],
        public readonly ?array $body = null,
        public readonly string $clientIp = '0.0.0.0',
        public readonly string $userAgent = '',
        public readonly array $headers = []
    ) {
    }
}
