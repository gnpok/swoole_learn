<?php

declare(strict_types=1);

namespace SwooleLearn\ShortUrl\Contracts;

interface StatsStoreInterface
{
    public function increment(string $code): int;

    public function getTotal(string $code): int;

    /**
     * @param array{visited_at: string, client_ip: string, user_agent: string} $visit
     */
    public function addRecentVisit(string $code, array $visit, int $maxItems = 20): void;

    /**
     * @return list<array{visited_at: string, client_ip: string, user_agent: string}>
     */
    public function getRecentVisits(string $code, int $limit = 20): array;
}
