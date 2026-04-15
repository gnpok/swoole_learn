<?php

declare(strict_types=1);

namespace SwooleLearn\ShortUrl\Infrastructure;

use Predis\ClientInterface;
use SwooleLearn\ShortUrl\Contracts\VisitEventQueueInterface;
use Throwable;

final class RedisVisitEventQueue implements VisitEventQueueInterface
{
    public function __construct(
        private readonly ClientInterface $redis,
        private readonly string $stream = 'shorturl:visit:stream',
        private readonly string $consumerGroup = 'visit-log-workers',
        private readonly string $consumerName = 'worker-1'
    ) {
    }

    /**
     * @param array{short_url_code: string, visited_at: string, client_ip: string, user_agent: string} $event
     */
    public function push(array $event): string
    {
        return (string) $this->redis->xadd($this->stream, [
            'short_url_code' => $event['short_url_code'],
            'visited_at' => $event['visited_at'],
            'client_ip' => $event['client_ip'],
            'user_agent' => $event['user_agent'],
        ]);
    }

    /**
     * @return list<array{id: string, values: array{
     *   short_url_code: string,
     *   visited_at: string,
     *   client_ip: string,
     *   user_agent: string
     * }}>
     */
    public function consume(int $count = 100, int $blockMs = 1000): array
    {
        $this->ensureConsumerGroup();

        $response = $this->redis->xreadgroup(
            $this->consumerGroup,
            $this->consumerName,
            $count,
            $blockMs,
            false,
            $this->stream,
            '>'
        );

        if (!is_array($response) || !isset($response[$this->stream]) || !is_array($response[$this->stream])) {
            return [];
        }

        $result = [];
        foreach ($response[$this->stream] as $entry) {
            if (!is_array($entry) || count($entry) < 2) {
                continue;
            }

            $id = (string) ($entry[0] ?? '');
            $values = $entry[1] ?? null;
            if ($id === '' || !is_array($values)) {
                continue;
            }

            $result[] = [
                'id' => $id,
                'values' => [
                    'short_url_code' => (string) ($values['short_url_code'] ?? ''),
                    'visited_at' => (string) ($values['visited_at'] ?? ''),
                    'client_ip' => (string) ($values['client_ip'] ?? ''),
                    'user_agent' => (string) ($values['user_agent'] ?? ''),
                ],
            ];
        }

        return $result;
    }

    /**
     * @param list<string> $messageIds
     */
    public function ack(array $messageIds): void
    {
        if ($messageIds === []) {
            return;
        }

        $this->redis->xack($this->stream, $this->consumerGroup, ...$messageIds);
    }

    private function ensureConsumerGroup(): void
    {
        try {
            $this->redis->xgroup->create($this->stream, $this->consumerGroup, '0', true);
        } catch (Throwable) {
            // Ignore BUSYGROUP and connection race errors for idempotent setup.
        }
    }
}
