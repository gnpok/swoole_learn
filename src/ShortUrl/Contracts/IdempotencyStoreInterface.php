<?php

declare(strict_types=1);

namespace SwooleLearn\ShortUrl\Contracts;

interface IdempotencyStoreInterface
{
    /**
     * Atomically claim key for one request.
     * Returns false when key already exists.
     */
    public function claim(string $key, string $value, int $ttlSeconds): bool;

    public function get(string $key): ?string;
}
