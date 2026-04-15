<?php

declare(strict_types=1);

namespace SwooleLearn\Tests\ShortUrl;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use SwooleLearn\ShortUrl\Contracts\ShortUrlRepositoryInterface;
use SwooleLearn\ShortUrl\Contracts\VisitEventQueueInterface;
use SwooleLearn\ShortUrl\Entity\ShortUrlPage;
use SwooleLearn\ShortUrl\Entity\ShortUrlRecord;
use SwooleLearn\ShortUrl\Observability\InMemoryPrometheusCollector;
use SwooleLearn\ShortUrl\Observability\LoggerInterface;
use SwooleLearn\ShortUrl\Service\ShortUrlVisitLogWorker;

final class ShortUrlVisitLogWorkerTest extends TestCase
{
    public function test_process_once_persists_and_acks_events(): void
    {
        $queue = new WorkerQueueDouble();
        $repository = new WorkerRepositoryDouble();

        $queue->push([
            'short_url_code' => 'code1234',
            'visited_at' => '2026-04-14T10:10:10+00:00',
            'client_ip' => '127.0.0.1',
            'user_agent' => 'phpunit',
            'event_key' => 'event-key-1',
        ]);

        $worker = new ShortUrlVisitLogWorker($queue, $repository);
        $processed = $worker->processOnce();

        self::assertSame(1, $processed);
        self::assertCount(1, $repository->visitLogs);
        self::assertSame('code1234', $repository->visitLogs[0]['code']);
        self::assertSame(['1-0'], $queue->acked);
    }

    public function test_process_once_skips_invalid_event_but_still_acks(): void
    {
        $queue = new WorkerQueueDouble();
        $repository = new WorkerRepositoryDouble();

        $queue->push([
            'short_url_code' => 'code1234',
            'visited_at' => '',
            'client_ip' => '127.0.0.1',
            'user_agent' => 'phpunit',
            'event_key' => 'event-key-2',
        ]);

        $worker = new ShortUrlVisitLogWorker($queue, $repository);
        $processed = $worker->processOnce();

        self::assertSame(0, $processed);
        self::assertCount(0, $repository->visitLogs);
        self::assertSame(['1-0'], $queue->acked);
    }

    public function test_process_once_retries_when_batch_and_single_write_fail(): void
    {
        $queue = new WorkerQueueDouble();
        $repository = new WorkerRepositoryDouble(throwOnBatch: true, throwOnSingle: true);

        $queue->push([
            'short_url_code' => 'code1234',
            'visited_at' => '2026-04-14T10:10:10+00:00',
            'client_ip' => '127.0.0.1',
            'user_agent' => 'phpunit',
            'event_key' => 'event-key-3',
            'attempt' => 2,
        ]);

        $worker = new ShortUrlVisitLogWorker($queue, $repository, maxAttempts: 5);
        $processed = $worker->processOnce();

        self::assertSame(0, $processed);
        self::assertCount(1, $queue->retries);
        self::assertSame(3, $queue->retries[0]['attempt']);
        self::assertSame(['1-0'], $queue->acked);
    }

    public function test_process_once_sends_to_dead_letter_after_max_attempts(): void
    {
        $queue = new WorkerQueueDouble();
        $repository = new WorkerRepositoryDouble(throwOnBatch: true, throwOnSingle: true);

        $queue->push([
            'short_url_code' => 'code1234',
            'visited_at' => '2026-04-14T10:10:10+00:00',
            'client_ip' => '127.0.0.1',
            'user_agent' => 'phpunit',
            'event_key' => 'event-key-4',
            'attempt' => 5,
        ]);

        $worker = new ShortUrlVisitLogWorker($queue, $repository, maxAttempts: 5);
        $processed = $worker->processOnce();

        self::assertSame(0, $processed);
        self::assertCount(1, $queue->deadLetters);
        self::assertSame(5, $queue->deadLetters[0]['attempt']);
        self::assertSame(['1-0'], $queue->acked);
    }

    public function test_process_once_reclaims_pending_messages_when_main_stream_empty(): void
    {
        $queue = new WorkerQueueDouble();
        $repository = new WorkerRepositoryDouble();

        $queue->seedPending([
            'short_url_code' => 'code-pending',
            'visited_at' => '2026-04-14T10:10:10+00:00',
            'client_ip' => '127.0.0.1',
            'user_agent' => 'phpunit',
            'event_key' => 'event-key-pending',
        ]);

        $worker = new ShortUrlVisitLogWorker($queue, $repository);
        $processed = $worker->processOnce(batchSize: 100, blockMs: 1, reclaimIdleMs: 60000, reclaimCount: 10);

        self::assertSame(1, $processed);
        self::assertTrue($queue->autoClaimCalled);
        self::assertCount(1, $repository->visitLogs);
        self::assertSame(['p-1-0'], $queue->acked);
    }

    public function test_worker_metrics_are_recorded_for_processing_and_retry(): void
    {
        $queue = new WorkerQueueDouble();
        $repository = new WorkerRepositoryDouble(throwOnBatch: true, throwOnSingle: true);
        $metrics = new InMemoryPrometheusCollector();

        $queue->push([
            'short_url_code' => 'code-obsv',
            'visited_at' => '2026-04-14T10:10:10+00:00',
            'client_ip' => '127.0.0.1',
            'user_agent' => 'phpunit',
            'event_key' => 'event-key-obsv',
            'attempt' => 1,
        ]);

        $worker = new ShortUrlVisitLogWorker($queue, $repository, maxAttempts: 5, metrics: $metrics);
        $processed = $worker->processOnce();

        self::assertSame(0, $processed);
        $dump = $metrics->renderPrometheus();
        self::assertStringContainsString('shorturl_worker_poll_total{result="non_empty"} 1', $dump);
        self::assertStringContainsString('shorturl_worker_retry_total{result="queued"} 1', $dump);
        self::assertStringContainsString('shorturl_worker_acks_total 1', $dump);
        self::assertStringContainsString('shorturl_worker_process_duration_seconds_bucket', $dump);
    }

    public function test_worker_logger_records_retry_and_dead_letter_context(): void
    {
        $queue = new WorkerQueueDouble();
        $repository = new WorkerRepositoryDouble(throwOnBatch: true, throwOnSingle: true);
        $logger = new WorkerCollectingLogger();

        $queue->push([
            'short_url_code' => 'code-dead',
            'visited_at' => '2026-04-14T10:10:10+00:00',
            'client_ip' => '127.0.0.1',
            'user_agent' => 'phpunit',
            'event_key' => 'event-key-dead',
            'attempt' => 5,
        ]);

        $worker = new ShortUrlVisitLogWorker($queue, $repository, maxAttempts: 5, logger: $logger);
        $worker->processOnce();

        self::assertNotEmpty($logger->records);
        $hasDeadLetterLog = false;
        foreach ($logger->records as $record) {
            if ($record['message'] === 'Visit log event moved to dead letter queue') {
                $hasDeadLetterLog = true;
                self::assertSame('code-dead', $record['context']['short_url_code'] ?? null);
                self::assertSame('event-key-dead', $record['context']['event_key'] ?? null);
            }
        }
        self::assertTrue($hasDeadLetterLog);
    }
}

final class WorkerCollectingLogger implements LoggerInterface
{
    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    public array $records = [];

    public function info(string $message, array $context = []): void
    {
        $this->records[] = ['level' => 'info', 'message' => $message, 'context' => $context];
    }

    public function warning(string $message, array $context = []): void
    {
        $this->records[] = ['level' => 'warning', 'message' => $message, 'context' => $context];
    }

    public function error(string $message, array $context = []): void
    {
        $this->records[] = ['level' => 'error', 'message' => $message, 'context' => $context];
    }
}

final class WorkerQueueDouble implements VisitEventQueueInterface
{
    /** @var list<array{id: string, values: array{short_url_code: string, visited_at: string, client_ip: string, user_agent: string, event_key: string, attempt: int}}> */
    private array $entries = [];

    /** @var list<string> */
    public array $acked = [];

    public bool $autoClaimCalled = false;

    /** @var list<array{event: array{short_url_code: string, visited_at: string, client_ip: string, user_agent: string, event_key: string}, attempt: int, reason: string}> */
    public array $retries = [];

    /** @var list<array{event: array{short_url_code: string, visited_at: string, client_ip: string, user_agent: string, event_key: string}, attempt: int, reason: string}> */
    public array $deadLetters = [];

    /** @var list<array{id: string, values: array{short_url_code: string, visited_at: string, client_ip: string, user_agent: string, event_key: string, attempt: int}}> */
    private array $pendingEntries = [];

    public function push(array $event): string
    {
        $id = (string) (count($this->entries) + 1) . '-0';
        $this->entries[] = [
            'id' => $id,
            'values' => [
                'short_url_code' => $event['short_url_code'],
                'visited_at' => $event['visited_at'],
                'client_ip' => $event['client_ip'],
                'user_agent' => $event['user_agent'],
                'event_key' => $event['event_key'],
                'attempt' => (int) ($event['attempt'] ?? 1),
            ],
        ];

        return $id;
    }

    public function consume(int $count = 100, int $blockMs = 1000): array
    {
        return array_splice($this->entries, 0, $count);
    }

    public function reclaim(int $minIdleMs = 60000, int $count = 100): array
    {
        $this->autoClaimCalled = true;

        return array_splice($this->pendingEntries, 0, $count);
    }

    public function ack(array $messageIds): void
    {
        foreach ($messageIds as $id) {
            $this->acked[] = $id;
        }
    }

    public function retry(array $event, int $attempt, string $reason): string
    {
        $this->retries[] = [
            'event' => $event,
            'attempt' => $attempt,
            'reason' => $reason,
        ];

        return 'retry-1';
    }

    public function deadLetter(array $event, int $attempt, string $reason): string
    {
        $this->deadLetters[] = [
            'event' => $event,
            'attempt' => $attempt,
            'reason' => $reason,
        ];

        return 'dead-1';
    }

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
    public function seedPending(array $event): void
    {
        $id = 'p-' . (string) (count($this->pendingEntries) + 1) . '-0';
        $this->pendingEntries[] = [
            'id' => $id,
            'values' => [
                'short_url_code' => $event['short_url_code'],
                'visited_at' => $event['visited_at'],
                'client_ip' => $event['client_ip'],
                'user_agent' => $event['user_agent'],
                'event_key' => $event['event_key'],
                'attempt' => (int) ($event['attempt'] ?? 1),
            ],
        ];
    }
}

final class WorkerRepositoryDouble implements ShortUrlRepositoryInterface
{
    /** @var list<array{code: string, client_ip: string, user_agent: string}> */
    public array $visitLogs = [];

    public function __construct(
        private readonly bool $throwOnBatch = false,
        private readonly bool $throwOnSingle = false
    ) {
    }

    public function save(ShortUrlRecord $record): ShortUrlRecord
    {
        return $record;
    }

    public function findByCode(string $code): ?ShortUrlRecord
    {
        return null;
    }

    public function existsByCode(string $code): bool
    {
        return false;
    }

    public function incrementVisits(string $code, DateTimeImmutable $visitedAt): void
    {
    }

    public function appendVisitLog(string $code, DateTimeImmutable $visitedAt, string $clientIp, string $userAgent): void
    {
        if ($this->throwOnSingle) {
            throw new \RuntimeException('single failed');
        }

        $this->visitLogs[] = [
            'code' => $code,
            'client_ip' => $clientIp,
            'user_agent' => $userAgent,
        ];
    }

    public function appendVisitLogsBatch(array $logs): int
    {
        if ($this->throwOnBatch) {
            throw new \RuntimeException('batch failed');
        }

        foreach ($logs as $log) {
            $this->visitLogs[] = [
                'code' => (string) $log['code'],
                'client_ip' => (string) $log['client_ip'],
                'user_agent' => (string) $log['user_agent'],
            ];
        }

        return count($logs);
    }

    public function disable(string $code): bool
    {
        return false;
    }

    public function paginate(int $page, int $pageSize, ?bool $isActive = null, ?string $keyword = null): ShortUrlPage
    {
        return new ShortUrlPage([], $page, $pageSize, 0);
    }

    public function bulkDisable(array $codes): array
    {
        return ['disabled' => 0, 'missing' => $codes];
    }
}
