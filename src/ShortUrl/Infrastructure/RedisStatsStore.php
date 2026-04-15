<?php

declare(strict_types=1);

namespace SwooleLearn\ShortUrl\Infrastructure;

use Predis\ClientInterface;
use SwooleLearn\ShortUrl\Contracts\StatsStoreInterface;

final class RedisStatsStore implements StatsStoreInterface
{
    public function __construct(
        private readonly ClientInterface $redis,
        private readonly string $counterPrefix = 'shorturl:stats:count:',
        private readonly string $recentPrefix = 'shorturl:stats:recent:',
        private readonly int $recentTtlSeconds = 604800
    ) {
    }

    public function increment(string $code): int
    {
        return (int) $this->redis->incr($this->counterPrefix . $code);
    }

    public function getTotal(string $code): int
    {
        return (int) ($this->redis->get($this->counterPrefix . $code) ?? 0);
    }

    public function addRecentVisit(string $code, array $visit, int $maxItems = 20): void
    {
        $max = max(1, $maxItems);
        $key = $this->recentPrefix . $code;
        $payload = (string) json_encode($visit, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $this->redis->lpush($key, [$payload]);
        $this->redis->ltrim($key, 0, $max - 1);
        $this->redis->expire($key, $this->recentTtlSeconds);
    }

    public function getRecentVisits(string $code, int $limit = 20): array
    {
        $max = max(1, $limit);
        $items = $this->redis->lrange($this->recentPrefix . $code, 0, $max - 1);

        $result = [];
        foreach ($items as $item) {
            if (!is_string($item)) {
                continue;
            }

            $decoded = json_decode($item, true);
            if (!is_array($decoded)) {
                continue;
            }

            $result[] = [
                'visited_at' => (string) ($decoded['visited_at'] ?? ''),
                'client_ip' => (string) ($decoded['client_ip'] ?? ''),
                'user_agent' => (string) ($decoded['user_agent'] ?? ''),
            ];
        }

        return $result;
    }
}
