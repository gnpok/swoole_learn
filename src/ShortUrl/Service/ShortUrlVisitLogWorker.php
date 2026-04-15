<?php

declare(strict_types=1);

namespace SwooleLearn\ShortUrl\Service;

use DateTimeImmutable;
use SwooleLearn\ShortUrl\Contracts\ShortUrlRepositoryInterface;
use SwooleLearn\ShortUrl\Contracts\VisitEventQueueInterface;
use SwooleLearn\ShortUrl\Observability\LoggerInterface;
use SwooleLearn\ShortUrl\Observability\MetricsCollectorInterface;
use SwooleLearn\ShortUrl\Observability\NullLogger;
use SwooleLearn\ShortUrl\Observability\NullMetricsCollector;
use Throwable;

final class ShortUrlVisitLogWorker
{
    public function __construct(
        private readonly VisitEventQueueInterface $queue,
        private readonly ShortUrlRepositoryInterface $repository,
        private readonly int $maxAttempts = 5,
        private readonly ?MetricsCollectorInterface $metrics = null,
        private readonly ?LoggerInterface $logger = null
    ) {
    }

    public function processOnce(
        int $batchSize = 100,
        int $blockMs = 1000,
        int $reclaimIdleMs = 60000,
        int $reclaimCount = 100
    ): int
    {
        $startedAtNs = hrtime(true);
        $metrics = $this->metrics ?? new NullMetricsCollector();
        $logger = $this->logger ?? new NullLogger();
        $events = $this->queue->consume($batchSize, $blockMs);
        if ($events === []) {
            $events = $this->queue->reclaim(
                minIdleMs: max(1, $reclaimIdleMs),
                count: max(1, $reclaimCount)
            );
        }
        if ($events === []) {
            $metrics->incrementCounter(
                name: 'shorturl_worker_poll_total',
                labels: ['result' => 'empty'],
                help: 'Worker poll operations grouped by result.'
            );
            return 0;
        }
        $metrics->incrementCounter(
            name: 'shorturl_worker_poll_total',
            labels: ['result' => 'non_empty'],
            help: 'Worker poll operations grouped by result.'
        );
        $metrics->setGauge(
            name: 'shorturl_worker_last_batch_size',
            value: (float) count($events),
            help: 'Number of messages in latest polled batch.'
        );

        $validEvents = [];
        $ackedIds = [];
        foreach ($events as $event) {
            $messageId = (string) ($event['id'] ?? '');
            $values = $event['values'] ?? null;
            if (!is_array($values) || !$this->isValidEvent($values)) {
                if ($messageId !== '') {
                    $ackedIds[] = $messageId;
                }
                $metrics->incrementCounter(
                    name: 'shorturl_worker_invalid_events_total',
                    labels: ['reason' => 'schema'],
                    help: 'Invalid events discarded by worker.'
                );
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
                $metrics->incrementCounter(
                    name: 'shorturl_worker_events_processed_total',
                    value: (float) $processed,
                    labels: ['mode' => 'batch', 'result' => 'success'],
                    help: 'Events processed by visit log worker.'
                );
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
                        $metrics->incrementCounter(
                            name: 'shorturl_worker_events_processed_total',
                            labels: ['mode' => 'single', 'result' => 'success'],
                            help: 'Events processed by visit log worker.'
                        );
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
                            $metrics->incrementCounter(
                                name: 'shorturl_worker_retry_total',
                                labels: ['result' => 'queued'],
                                help: 'Worker retries scheduled for failed events.'
                            );
                            $logger->warning('Visit log event scheduled for retry', [
                                'short_url_code' => $values['short_url_code'],
                                'event_key' => $values['event_key'],
                                'attempt' => $attempt + 1,
                                'error' => $singleException->getMessage(),
                            ]);
                        } else {
                            $this->queue->deadLetter([
                                'short_url_code' => $values['short_url_code'],
                                'visited_at' => $values['visited_at'],
                                'client_ip' => $values['client_ip'],
                                'user_agent' => $values['user_agent'],
                                'event_key' => $values['event_key'],
                            ], $attempt, $singleException->getMessage());
                            $metrics->incrementCounter(
                                name: 'shorturl_worker_dead_letter_total',
                                labels: ['result' => 'queued'],
                                help: 'Events sent to dead letter queue by worker.'
                            );
                            $logger->error('Visit log event moved to dead letter queue', [
                                'short_url_code' => $values['short_url_code'],
                                'event_key' => $values['event_key'],
                                'attempt' => $attempt,
                                'error' => $singleException->getMessage(),
                            ]);
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
            $metrics->incrementCounter(
                name: 'shorturl_worker_acks_total',
                value: (float) count($ackedIds),
                help: 'Acked message IDs by visit log worker.'
            );
        }
        $metrics->observeHistogram(
            name: 'shorturl_worker_process_duration_seconds',
            value: (hrtime(true) - $startedAtNs) / 1_000_000_000,
            help: 'Duration histogram for worker processOnce.'
        );

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
