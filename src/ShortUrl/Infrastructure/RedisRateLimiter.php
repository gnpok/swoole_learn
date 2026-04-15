<?php

declare(strict_types=1);

namespace SwooleLearn\ShortUrl\Infrastructure;

use Predis\ClientInterface;
use SwooleLearn\ShortUrl\Contracts\RateLimiterInterface;

final class RedisRateLimiter implements RateLimiterInterface
{
    public function __construct(
        private readonly ClientInterface $redis,
        private readonly string $prefix = 'shorturl:rl:'
    ) {
    }

    public function hit(string $key, int $windowSeconds): int
    {
        $cacheKey = $this->prefix . $key;
        $count = (int) $this->redis->incr($cacheKey);

        if ($count === 1) {
            $this->redis->expire($cacheKey, max(1, $windowSeconds));
        }

        return $count;
    }
}
