<?php

declare(strict_types=1);

namespace SwooleLearn\ShortUrl\Service;

use DateTimeImmutable;
use SwooleLearn\ShortUrl\Contracts\ShortUrlRepositoryInterface;
use SwooleLearn\ShortUrl\Contracts\VisitEventQueueInterface;
use Throwable;

final class ShortUrlVisitLogWorker
{
    public function __construct(
        private readonly VisitEventQueueInterface $queue,
        private readonly ShortUrlRepositoryInterface $repository,
        private readonly int $maxAttempts = 5
    ) {
    }

    public function processOnce(int $batchSize = 100, int $blockMs = 1000): int
    {
        $events = $this->queue->consume($batchSize, $blockMs);
        if ($events === []) {
            $events = $this->queue->reclaim(60000, $batchSize);
        }
        if ($events === []) {
            return 0;
        }

        $validEvents = [];
        $ackedIds = [];
        foreach ($events as $event) {
            $messageId = (string) ($event['id'] ?? '');
            $values = $event['values'] ?? null;
            if (!is_array($values) || !$this->isValidEvent($values)) {
                if ($messageId !== '') {
                    $ackedIds[] = $messageId;
                }
                continue;
            }

            $validEvents[] = [
                'id' => $messageId,
                'values' => $values,
            ];
        }

        $processed = 0;
        if ($validEvents !== []) {
            try {
                $batchPayloads = array_map(
                    static fn (array $event): array => [
                        'code' => $event['values']['short_url_code'],
                        'visited_at' => new DateTimeImmutable($event['values']['visited_at']),
                        'client_ip' => $event['values']['client_ip'],
                        'user_agent' => $event['values']['user_agent'],
                        'event_key' => $event['values']['event_key'],
                    ],
                    $validEvents
                );
                $processed = $this->repository->appendVisitLogsBatch($batchPayloads);
                foreach ($validEvents as $event) {
                    if ($event['id'] !== '') {
                        $ackedIds[] = $event['id'];
                    }
                }
            } catch (Throwable $exception) {
                foreach ($validEvents as $event) {
                    $messageId = $event['id'];
                    $values = $event['values'];
                    try {
                        $this->repository->appendVisitLog(
                            code: $values['short_url_code'],
                            visitedAt: new DateTimeImmutable($values['visited_at']),
                            clientIp: $values['client_ip'],
                            userAgent: $values['user_agent']
                        );
                        $processed++;
                    } catch (Throwable $singleException) {
                        $attempt = max(1, (int) $values['attempt']);
                        if ($attempt < $this->maxAttempts) {
                            $this->queue->retry([
                                'short_url_code' => $values['short_url_code'],
                                'visited_at' => $values['visited_at'],
                                'client_ip' => $values['client_ip'],
                                'user_agent' => $values['user_agent'],
                                'event_key' => $values['event_key'],
                            ], $attempt + 1, $singleException->getMessage());
                        } else {
                            $this->queue->deadLetter([
                                'short_url_code' => $values['short_url_code'],
                                'visited_at' => $values['visited_at'],
                                'client_ip' => $values['client_ip'],
                                'user_agent' => $values['user_agent'],
                                'event_key' => $values['event_key'],
                            ], $attempt, $singleException->getMessage());
                        }
                    }

                    if ($messageId !== '') {
                        $ackedIds[] = $messageId;
                    }
                }
            }
        }

        if ($ackedIds !== []) {
            $this->queue->ack(array_values(array_unique($ackedIds)));
        }

        return $processed;
    }

    public function runLoop(int $batchSize = 100, int $blockMs = 5000): void
    {
        while (true) {
            $this->processOnce($batchSize, $blockMs);
        }
    }

    /**
     * @param array<string, string> $event
     */
    private function isValidEvent(array $event): bool
    {
        return isset($event['short_url_code'], $event['visited_at'], $event['client_ip'], $event['user_agent'])
            && $event['short_url_code'] !== ''
            && $event['visited_at'] !== ''
            && isset($event['event_key'])
            && $event['event_key'] !== '';
    }
}
