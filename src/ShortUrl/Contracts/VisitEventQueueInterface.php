<?php

declare(strict_types=1);

namespace SwooleLearn\ShortUrl\Contracts;

interface VisitEventQueueInterface
{
    /**
     * @param array{
     *   short_url_code: string,
     *   visited_at: string,
     *   client_ip: string,
     *   user_agent: string,
     *   event_key: string,
     *   attempt?: int
     * } $event
     */
    public function push(array $event): string;

    /**
     * @return list<array{id: string, values: array{
     *   short_url_code: string,
     *   visited_at: string,
     *   client_ip: string,
     *   user_agent: string,
     *   event_key: string,
     *   attempt: int
     * }}>
     */
    public function consume(int $count = 100, int $blockMs = 1000): array;

    /**
     * @param list<string> $messageIds
     */
    public function ack(array $messageIds): void;

    /**
     * @param array{
     *   short_url_code: string,
     *   visited_at: string,
     *   client_ip: string,
     *   user_agent: string,
     *   event_key: string
     * } $event
     */
    public function retry(array $event, int $attempt, string $reason): string;

    /**
     * @param array{
     *   short_url_code: string,
     *   visited_at: string,
     *   client_ip: string,
     *   user_agent: string,
     *   event_key: string
     * } $event
     */
    public function deadLetter(array $event, int $attempt, string $reason): string;

    /**
     * Attempt to reclaim stale pending messages from other consumers.
     *
     * @return list<array{id: string, values: array{
     *   short_url_code: string,
     *   visited_at: string,
     *   client_ip: string,
     *   user_agent: string,
     *   event_key: string,
     *   attempt: int
     * }}>
     */
    public function reclaim(int $minIdleMs = 60000, int $count = 100): array;
}
