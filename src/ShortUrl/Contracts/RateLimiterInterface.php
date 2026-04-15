<?php

declare(strict_types=1);

namespace SwooleLearn\ShortUrl\Contracts;

interface RateLimiterInterface
{
    public function hit(string $key, int $windowSeconds): int;
}
