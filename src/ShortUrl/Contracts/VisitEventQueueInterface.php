<?php

declare(strict_types=1);

namespace SwooleLearn\ShortUrl\Contracts;

interface VisitEventQueueInterface
{
    /**
     * @param array{short_url_code: string, visited_at: string, client_ip: string, user_agent: string} $event
     */
    public function push(array $event): string;

    /**
     * @return list<array{id: string, values: array{
     *   short_url_code: string,
     *   visited_at: string,
     *   client_ip: string,
     *   user_agent: string
     * }}>
     */
    public function consume(int $count = 100, int $blockMs = 1000): array;

    /**
     * @param list<string> $messageIds
     */
    public function ack(array $messageIds): void;
}
