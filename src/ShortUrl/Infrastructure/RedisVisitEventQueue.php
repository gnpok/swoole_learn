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
        private readonly string $deadLetterStream = 'shorturl:visit:stream:dlq',
        private readonly string $consumerGroup = 'visit-log-workers',
        private readonly string $consumerName = 'worker-1'
    ) {
    }

    /**
     * @param array{short_url_code: string, visited_at: string, client_ip: string, user_agent: string} $event
     */
    public function push(array $event): string
    {
        $eventKey = (string) ($event['event_key'] ?? '');
        $attempt = max(1, (int) ($event['attempt'] ?? 1));

        return (string) $this->redis->xadd($this->stream, [
            'short_url_code' => $event['short_url_code'],
            'visited_at' => $event['visited_at'],
            'client_ip' => $event['client_ip'],
            'user_agent' => $event['user_agent'],
            'event_key' => $eventKey,
            'attempt' => (string) $attempt,
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
                    'event_key' => (string) ($values['event_key'] ?? ''),
                    'attempt' => max(1, (int) ($values['attempt'] ?? 1)),
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

    public function retry(array $event, int $attempt, string $reason): string
    {
        return (string) $this->redis->xadd($this->stream, [
            'short_url_code' => $event['short_url_code'],
            'visited_at' => $event['visited_at'],
            'client_ip' => $event['client_ip'],
            'user_agent' => $event['user_agent'],
            'event_key' => $event['event_key'],
            'attempt' => (string) max(1, $attempt),
            'retry_reason' => mb_substr($reason, 0, 255),
        ]);
    }

    public function deadLetter(array $event, int $attempt, string $reason): string
    {
        return (string) $this->redis->xadd($this->deadLetterStream, [
            'short_url_code' => $event['short_url_code'],
            'visited_at' => $event['visited_at'],
            'client_ip' => $event['client_ip'],
            'user_agent' => $event['user_agent'],
            'event_key' => $event['event_key'],
            'attempt' => (string) max(1, $attempt),
            'failed_reason' => mb_substr($reason, 0, 255),
            'failed_at' => (new \DateTimeImmutable())->format(DATE_ATOM),
        ]);
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
