<?php

declare(strict_types=1);

namespace SwooleLearn\ShortUrl\Infrastructure;

use Predis\ClientInterface;
use SwooleLearn\ShortUrl\Contracts\IdempotencyStoreInterface;

final class RedisIdempotencyStore implements IdempotencyStoreInterface
{
    public function __construct(
        private readonly ClientInterface $redis,
        private readonly string $prefix = 'shorturl:idem:'
    ) {
    }

    public function claim(string $key, string $value, int $ttlSeconds): bool
    {
        $ttl = max(1, $ttlSeconds);
        $result = $this->redis->set(
            $this->prefix . $key,
            $value,
            'EX',
            $ttl,
            'NX'
        );

        return $result === 'OK';
    }

    public function get(string $key): ?string
    {
        $value = $this->redis->get($this->prefix . $key);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
