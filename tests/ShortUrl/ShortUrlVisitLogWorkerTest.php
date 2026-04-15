<?php

declare(strict_types=1);

namespace SwooleLearn\Tests\ShortUrl;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use SwooleLearn\ShortUrl\Service\ShortUrlVisitLogWorker;

final class ShortUrlVisitLogWorkerTest extends TestCase
{
    public function test_process_once_persists_and_acks_events(): void
    {
        $queue = new FakeVisitEventQueue();
        $repository = new FakeShortUrlRepository();

        $queue->push([
            'short_url_code' => 'code1234',
            'visited_at' => '2026-04-14T10:10:10+00:00',
            'client_ip' => '127.0.0.1',
            'user_agent' => 'phpunit',
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
        $queue = new FakeVisitEventQueue();
        $repository = new FakeShortUrlRepository();

        $queue->push([
            'short_url_code' => 'code1234',
            'visited_at' => '',
            'client_ip' => '127.0.0.1',
            'user_agent' => 'phpunit',
        ]);

        $worker = new ShortUrlVisitLogWorker($queue, $repository);
        $processed = $worker->processOnce();

        self::assertSame(0, $processed);
        self::assertCount(0, $repository->visitLogs);
        self::assertSame(['1-0'], $queue->acked);
    }
}
