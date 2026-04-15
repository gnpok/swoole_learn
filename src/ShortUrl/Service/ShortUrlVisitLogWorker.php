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
        private readonly ShortUrlRepositoryInterface $repository
    ) {
    }

    public function processOnce(int $batchSize = 100, int $blockMs = 1000): int
    {
        $events = $this->queue->consume($batchSize, $blockMs);
        if ($events === []) {
            return 0;
        }

        $processed = 0;
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

            try {
                $this->repository->appendVisitLog(
                    code: $values['short_url_code'],
                    visitedAt: new DateTimeImmutable($values['visited_at']),
                    clientIp: $values['client_ip'],
                    userAgent: $values['user_agent']
                );
                $processed++;
                if ($messageId !== '') {
                    $ackedIds[] = $messageId;
                }
            } catch (Throwable) {
                // Ignore malformed or unpersistable events for learning sample.
                if ($messageId !== '') {
                    $ackedIds[] = $messageId;
                }
            }
        }

        if ($ackedIds !== []) {
            $this->queue->ack($ackedIds);
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
            && $event['visited_at'] !== '';
    }
}
