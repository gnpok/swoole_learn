<?php

declare(strict_types=1);

namespace SwooleLearn\ShortUrl\Infrastructure;

use Predis\ClientInterface;
use SwooleLearn\ShortUrl\Contracts\ShortUrlCacheInterface;
use SwooleLearn\ShortUrl\Entity\ShortUrlRecord;

final class RedisShortUrlCache implements ShortUrlCacheInterface
{
    public function __construct(
        private readonly ClientInterface $redis,
        private readonly string $prefix = 'shorturl:meta:'
    ) {
    }

    public function get(string $code): ?ShortUrlRecord
    {
        $cached = $this->redis->get($this->prefix . $code);
        if (!is_string($cached) || $cached === '') {
            return null;
        }

        $decoded = json_decode($cached, true);
        if (!is_array($decoded)) {
            return null;
        }

        return ShortUrlRecord::fromCachePayload($decoded);
    }

    public function put(ShortUrlRecord $record, int $ttlSeconds): void
    {
        $ttl = $ttlSeconds > 0 ? $ttlSeconds : 60;
        $this->redis->setex(
            $this->prefix . $record->code,
            $ttl,
            (string) json_encode($record->toCachePayload(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }

    public function forget(string $code): void
    {
        $this->redis->del([$this->prefix . $code]);
    }
}
