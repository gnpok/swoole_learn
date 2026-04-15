<?php

declare(strict_types=1);

namespace SwooleLearn\ShortUrl\Contracts;

use SwooleLearn\ShortUrl\Entity\ShortUrlRecord;

interface ShortUrlCacheInterface
{
    public function get(string $code): ?ShortUrlRecord;

    public function put(ShortUrlRecord $record, int $ttlSeconds): void;

    public function forget(string $code): void;
}
