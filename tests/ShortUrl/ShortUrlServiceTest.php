<?php

declare(strict_types=1);

namespace SwooleLearn\Tests\ShortUrl;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use SwooleLearn\ShortUrl\Contracts\IdempotencyStoreInterface;
use SwooleLearn\ShortUrl\Contracts\RateLimiterInterface;
use SwooleLearn\ShortUrl\Contracts\ShortCodeGeneratorInterface;
use SwooleLearn\ShortUrl\Contracts\ShortUrlCacheInterface;
use SwooleLearn\ShortUrl\Contracts\ShortUrlRepositoryInterface;
use SwooleLearn\ShortUrl\Contracts\StatsStoreInterface;
use SwooleLearn\ShortUrl\Contracts\VisitEventQueueInterface;
use SwooleLearn\ShortUrl\Entity\ShortUrlListItem;
use SwooleLearn\ShortUrl\Entity\ShortUrlPage;
use SwooleLearn\ShortUrl\Entity\ShortUrlRecord;
use SwooleLearn\ShortUrl\Exception\ConflictException;
use SwooleLearn\ShortUrl\Exception\InactiveShortUrlException;
use SwooleLearn\ShortUrl\Exception\RateLimitException;
use SwooleLearn\ShortUrl\Exception\ValidationException;
use SwooleLearn\ShortUrl\Service\ShortUrlService;

final class ShortUrlServiceTest extends TestCase
{
    public function test_create_short_url_with_generated_code_and_cache(): void
    {
        $repository = new FakeShortUrlRepository();
        $cache = new FakeShortUrlCache();
        $stats = new FakeStatsStore();
        $limiter = new FakeRateLimiter();
        $generator = new FakeCodeGenerator(['abc1234']);

        $service = $this->newService($repository, $cache, $stats, $limiter, $generator);
        $record = $service->create('https://example.com/docs/swoole');

        self::assertSame('abc1234', $record->code);
        self::assertSame('https://example.com/docs/swoole', $record->originalUrl);
        self::assertNotNull($cache->get('abc1234'));
        self::assertSame('http://127.0.0.1:9501/r/abc1234', $service->buildShortUrl('abc1234'));
    }

    public function test_create_with_custom_code_conflict_throws_exception(): void
    {
        $repository = new FakeShortUrlRepository();
        $cache = new FakeShortUrlCache();
        $stats = new FakeStatsStore();
        $limiter = new FakeRateLimiter();
        $generator = new FakeCodeGenerator();

        $repository->seed(new ShortUrlRecord(
            id: 1,
            code: 'learn01',
            originalUrl: 'https://example.com/old',
            createdAt: new DateTimeImmutable('2026-01-01T00:00:00+00:00'),
            expiresAt: null,
            isActive: true,
            totalVisits: 0,
            lastVisitedAt: null
        ));

        $service = $this->newService($repository, $cache, $stats, $limiter, $generator);

        $this->expectException(ConflictException::class);
        $service->create('https://example.com/new', 'learn01');
    }

    public function test_create_enforces_rate_limit(): void
    {
        $service = $this->newService(
            new FakeShortUrlRepository(),
            new FakeShortUrlCache(),
            new FakeStatsStore(),
            new FakeRateLimiter(['create:127.0.0.1' => 31]),
            new FakeCodeGenerator(['okcode1'])
        );

        $this->expectException(RateLimitException::class);
        $service->create('https://example.com/rate-limit', null, null, '127.0.0.1');
    }

    public function test_resolve_updates_stats_and_visit_log(): void
    {
        $repository = new FakeShortUrlRepository();
        $cache = new FakeShortUrlCache();
        $stats = new FakeStatsStore();
        $limiter = new FakeRateLimiter();
        $generator = new FakeCodeGenerator(['abc9999']);

        $queue = new FakeVisitEventQueue();
        $service = $this->newService($repository, $cache, $stats, $limiter, $generator, null, null, $queue);
        $created = $service->create('https://example.com/resolve');
        $resolved = $service->resolve($created->code, '10.0.0.8', 'phpunit');

        self::assertSame($created->code, $resolved->code);
        self::assertSame(1, $stats->getTotal($created->code));
        self::assertCount(1, $queue->events);
        self::assertSame('10.0.0.8', $queue->events[0]['client_ip']);
        self::assertSame($created->code, $queue->events[0]['short_url_code']);
    }

    public function test_resolve_expired_short_url_throws_inactive_exception(): void
    {
        $repository = new FakeShortUrlRepository();
        $cache = new FakeShortUrlCache();
        $stats = new FakeStatsStore();
        $limiter = new FakeRateLimiter();
        $generator = new FakeCodeGenerator();

        $expiredRecord = new ShortUrlRecord(
            id: 1,
            code: 'old1234',
            originalUrl: 'https://example.com/expired',
            createdAt: new DateTimeImmutable('2026-01-01T00:00:00+00:00'),
            expiresAt: new DateTimeImmutable('2026-01-02T00:00:00+00:00'),
            isActive: true,
            totalVisits: 0,
            lastVisitedAt: null
        );
        $repository->seed($expiredRecord);

        $service = $this->newService(
            $repository,
            $cache,
            $stats,
            $limiter,
            $generator,
            static fn (): DateTimeImmutable => new DateTimeImmutable('2026-01-03T00:00:00+00:00')
        );

        $this->expectException(InactiveShortUrlException::class);
        $service->resolve('old1234', '127.0.0.1', 'phpunit');
    }

    public function test_disable_short_url_removes_cache(): void
    {
        $repository = new FakeShortUrlRepository();
        $cache = new FakeShortUrlCache();
        $stats = new FakeStatsStore();
        $limiter = new FakeRateLimiter();
        $generator = new FakeCodeGenerator(['abc1111']);
        $service = $this->newService($repository, $cache, $stats, $limiter, $generator);

        $record = $service->create('https://example.com/disable');
        self::assertNotNull($cache->get($record->code));

        $service->disable($record->code);
        self::assertNull($cache->get($record->code));
    }

    public function test_get_detail_combines_repository_and_redis_stats(): void
    {
        $repository = new FakeShortUrlRepository();
        $cache = new FakeShortUrlCache();
        $stats = new FakeStatsStore();
        $limiter = new FakeRateLimiter();
        $generator = new FakeCodeGenerator(['abc5555']);
        $service = $this->newService($repository, $cache, $stats, $limiter, $generator);

        $record = $service->create('https://example.com/stats');
        $stats->increment($record->code);
        $stats->increment($record->code);
        $stats->addRecentVisit($record->code, [
            'visited_at' => '2026-04-14T10:10:10+00:00',
            'client_ip' => '1.1.1.1',
            'user_agent' => 'ua-1',
        ]);

        $detail = $service->getDetail($record->code);

        self::assertSame(2, $detail['total_visits']);
        self::assertCount(1, $detail['recent_visits']);
        self::assertSame('1.1.1.1', $detail['recent_visits'][0]['client_ip']);
    }

    public function test_create_idempotent_returns_existing_record_when_key_reused(): void
    {
        $repository = new FakeShortUrlRepository();
        $cache = new FakeShortUrlCache();
        $stats = new FakeStatsStore();
        $limiter = new FakeRateLimiter();
        $generator = new FakeCodeGenerator(['idem001', 'idem002']);
        $idempotency = new FakeIdempotencyStore();
        $queue = new FakeVisitEventQueue();

        $service = $this->newService(
            $repository,
            $cache,
            $stats,
            $limiter,
            $generator,
            null,
            $idempotency,
            $queue
        );

        $first = $service->createIdempotent('key-123', 'https://example.com/idem');
        $second = $service->createIdempotent('key-123', 'https://example.com/idem-changed');

        self::assertSame($first->code, $second->code);
        self::assertSame('https://example.com/idem', $second->originalUrl);
        self::assertSame(1, $repository->count());
    }

    public function test_list_short_urls_supports_filters_and_pagination(): void
    {
        $repository = new FakeShortUrlRepository();
        $cache = new FakeShortUrlCache();
        $stats = new FakeStatsStore();
        $limiter = new FakeRateLimiter();
        $generator = new FakeCodeGenerator();

        $repository->seed(new ShortUrlRecord(
            id: 1,
            code: 'keep001',
            originalUrl: 'https://example.com/keep',
            createdAt: new DateTimeImmutable('2026-04-01T00:00:00+00:00'),
            expiresAt: null,
            isActive: true,
            totalVisits: 3,
            lastVisitedAt: null
        ));
        $repository->seed(new ShortUrlRecord(
            id: 2,
            code: 'off001',
            originalUrl: 'https://example.com/off',
            createdAt: new DateTimeImmutable('2026-04-02T00:00:00+00:00'),
            expiresAt: null,
            isActive: false,
            totalVisits: 1,
            lastVisitedAt: null
        ));

        $service = $this->newService(
            $repository,
            $cache,
            $stats,
            $limiter,
            $generator
        );

        $page = $service->listShortUrls(1, 10, true, 'keep');

        self::assertSame(1, $page['total']);
        self::assertCount(1, $page['items']);
        self::assertSame('keep001', $page['items'][0]['code']);
    }

    public function test_bulk_disable_returns_missing_codes(): void
    {
        $repository = new FakeShortUrlRepository();
        $cache = new FakeShortUrlCache();
        $stats = new FakeStatsStore();
        $limiter = new FakeRateLimiter();
        $generator = new FakeCodeGenerator();

        $repository->seed(new ShortUrlRecord(
            id: 1,
            code: 'on001',
            originalUrl: 'https://example.com/on001',
            createdAt: new DateTimeImmutable('2026-04-01T00:00:00+00:00'),
            expiresAt: null,
            isActive: true,
            totalVisits: 0,
            lastVisitedAt: null
        ));

        $service = $this->newService(
            $repository,
            $cache,
            $stats,
            $limiter,
            $generator
        );

        $result = $service->bulkDisable(['on001', 'miss001']);

        self::assertSame(2, $result['requested']);
        self::assertSame(1, $result['disabled']);
        self::assertSame(['miss001'], $result['missing']);
        self::assertFalse($repository->isActive('on001'));
    }

    public function test_bulk_disable_requires_at_least_one_code(): void
    {
        $service = $this->newService(
            new FakeShortUrlRepository(),
            new FakeShortUrlCache(),
            new FakeStatsStore(),
            new FakeRateLimiter(),
            new FakeCodeGenerator()
        );

        $this->expectException(ValidationException::class);
        $service->bulkDisable([]);
    }

    private function newService(
        ShortUrlRepositoryInterface $repository,
        ShortUrlCacheInterface $cache,
        StatsStoreInterface $statsStore,
        RateLimiterInterface $rateLimiter,
        ShortCodeGeneratorInterface $codeGenerator,
        ?callable $clock = null,
        ?IdempotencyStoreInterface $idempotencyStore = null,
        ?VisitEventQueueInterface $visitEventQueue = null
    ): ShortUrlService {
        return new ShortUrlService(
            repository: $repository,
            cache: $cache,
            statsStore: $statsStore,
            rateLimiter: $rateLimiter,
            codeGenerator: $codeGenerator,
            idempotencyStore: $idempotencyStore ?? new FakeIdempotencyStore(),
            visitEventQueue: $visitEventQueue ?? new FakeVisitEventQueue(),
            publicBaseUrl: 'http://127.0.0.1:9501',
            clock: $clock,
            createLimit: 30,
            createWindowSeconds: 60
        );
    }
}

final class FakeShortUrlRepository implements ShortUrlRepositoryInterface
{
    /** @var array<string, ShortUrlRecord> */
    private array $records = [];

    /** @var list<array{code: string, client_ip: string, user_agent: string}> */
    public array $visitLogs = [];

    private int $nextId = 1;

    public function seed(ShortUrlRecord $record): void
    {
        $this->records[$record->code] = $record;
        $this->nextId = max($this->nextId, ($record->id ?? 0) + 1);
    }

    public function save(ShortUrlRecord $record): ShortUrlRecord
    {
        $saved = new ShortUrlRecord(
            id: $this->nextId++,
            code: $record->code,
            originalUrl: $record->originalUrl,
            createdAt: $record->createdAt,
            expiresAt: $record->expiresAt,
            isActive: $record->isActive,
            totalVisits: $record->totalVisits,
            lastVisitedAt: $record->lastVisitedAt
        );
        $this->records[$saved->code] = $saved;

        return $saved;
    }

    public function findByCode(string $code): ?ShortUrlRecord
    {
        return $this->records[$code] ?? null;
    }

    public function existsByCode(string $code): bool
    {
        return isset($this->records[$code]);
    }

    public function incrementVisits(string $code, DateTimeImmutable $visitedAt): void
    {
        $record = $this->records[$code] ?? null;
        if ($record === null) {
            return;
        }

        $this->records[$code] = new ShortUrlRecord(
            id: $record->id,
            code: $record->code,
            originalUrl: $record->originalUrl,
            createdAt: $record->createdAt,
            expiresAt: $record->expiresAt,
            isActive: $record->isActive,
            totalVisits: $record->totalVisits + 1,
            lastVisitedAt: $visitedAt
        );
    }

    public function appendVisitLog(string $code, DateTimeImmutable $visitedAt, string $clientIp, string $userAgent): void
    {
        $this->visitLogs[] = [
            'code' => $code,
            'client_ip' => $clientIp,
            'user_agent' => $userAgent,
        ];
    }

    public function disable(string $code): bool
    {
        $record = $this->records[$code] ?? null;
        if ($record === null) {
            return false;
        }

        $this->records[$code] = new ShortUrlRecord(
            id: $record->id,
            code: $record->code,
            originalUrl: $record->originalUrl,
            createdAt: $record->createdAt,
            expiresAt: $record->expiresAt,
            isActive: false,
            totalVisits: $record->totalVisits,
            lastVisitedAt: $record->lastVisitedAt
        );

        return true;
    }

    public function paginate(int $page, int $pageSize, ?bool $isActive = null, ?string $keyword = null): ShortUrlPage
    {
        $items = array_values($this->records);

        if ($isActive !== null) {
            $items = array_values(array_filter($items, static fn (ShortUrlRecord $record): bool => $record->isActive === $isActive));
        }

        if ($keyword !== null && $keyword !== '') {
            $items = array_values(array_filter(
                $items,
                static fn (ShortUrlRecord $record): bool => str_contains($record->code, $keyword)
                    || str_contains($record->originalUrl, $keyword)
            ));
        }

        $total = count($items);
        $offset = max(0, ($page - 1) * $pageSize);
        $slice = array_slice($items, $offset, $pageSize);

        return new ShortUrlPage(
            items: array_map(
                static fn (ShortUrlRecord $record): ShortUrlListItem => new ShortUrlListItem(
                    code: $record->code,
                    originalUrl: $record->originalUrl,
                    isActive: $record->isActive,
                    expiresAt: $record->expiresAt,
                    totalVisits: $record->totalVisits,
                    lastVisitedAt: $record->lastVisitedAt,
                    createdAt: $record->createdAt
                ),
                $slice
            ),
            page: $page,
            perPage: $pageSize,
            total: $total
        );
    }

    public function bulkDisable(array $codes): array
    {
        $disabled = 0;
        $missing = [];

        foreach ($codes as $code) {
            if (!isset($this->records[$code])) {
                $missing[] = $code;
                continue;
            }

            $record = $this->records[$code];
            $this->records[$code] = new ShortUrlRecord(
                id: $record->id,
                code: $record->code,
                originalUrl: $record->originalUrl,
                createdAt: $record->createdAt,
                expiresAt: $record->expiresAt,
                isActive: false,
                totalVisits: $record->totalVisits,
                lastVisitedAt: $record->lastVisitedAt
            );
            $disabled++;
        }

        return [
            'disabled' => $disabled,
            'missing' => $missing,
        ];
    }

    public function count(): int
    {
        return count($this->records);
    }

    public function isActive(string $code): bool
    {
        return $this->records[$code]?->isActive ?? false;
    }
}

final class FakeShortUrlCache implements ShortUrlCacheInterface
{
    /** @var array<string, ShortUrlRecord> */
    private array $cache = [];

    public function get(string $code): ?ShortUrlRecord
    {
        return $this->cache[$code] ?? null;
    }

    public function put(ShortUrlRecord $record, int $ttlSeconds): void
    {
        $this->cache[$record->code] = $record;
    }

    public function forget(string $code): void
    {
        unset($this->cache[$code]);
    }
}

final class FakeStatsStore implements StatsStoreInterface
{
    /** @var array<string, int> */
    private array $counter = [];

    /** @var array<string, list<array{visited_at: string, client_ip: string, user_agent: string}>> */
    private array $recent = [];

    public function increment(string $code): int
    {
        $this->counter[$code] = ($this->counter[$code] ?? 0) + 1;

        return $this->counter[$code];
    }

    public function getTotal(string $code): int
    {
        return $this->counter[$code] ?? 0;
    }

    public function addRecentVisit(string $code, array $visit, int $maxItems = 20): void
    {
        $this->recent[$code] ??= [];
        array_unshift($this->recent[$code], $visit);
        $this->recent[$code] = array_slice($this->recent[$code], 0, $maxItems);
    }

    public function getRecentVisits(string $code, int $limit = 20): array
    {
        return array_slice($this->recent[$code] ?? [], 0, $limit);
    }
}

final class FakeRateLimiter implements RateLimiterInterface
{
    /** @var array<string, int> */
    private array $counter = [];

    /**
     * @param array<string, int> $seeds
     */
    public function __construct(array $seeds = [])
    {
        $this->counter = $seeds;
    }

    public function hit(string $key, int $windowSeconds): int
    {
        $this->counter[$key] = ($this->counter[$key] ?? 0) + 1;

        return $this->counter[$key];
    }
}

final class FakeCodeGenerator implements ShortCodeGeneratorInterface
{
    /** @var list<string> */
    private array $codes;

    /**
     * @param list<string> $codes
     */
    public function __construct(array $codes = [])
    {
        $this->codes = $codes;
    }

    public function generate(int $length = 7): string
    {
        if ($this->codes !== []) {
            $value = array_shift($this->codes);

            return is_string($value) ? $value : str_repeat('x', $length);
        }

        return str_repeat('x', $length);
    }
}

final class FakeIdempotencyStore implements IdempotencyStoreInterface
{
    /** @var array<string, string> */
    private array $store = [];

    public function claim(string $key, string $value, int $ttlSeconds): bool
    {
        if (isset($this->store[$key])) {
            return false;
        }

        $this->store[$key] = $value;

        return true;
    }

    public function get(string $key): ?string
    {
        return $this->store[$key] ?? null;
    }
}

final class FakeVisitEventQueue implements VisitEventQueueInterface
{
    /** @var list<array{short_url_code: string, visited_at: string, client_ip: string, user_agent: string}> */
    public array $events = [];

    /** @var list<array{id: string, values: array{short_url_code: string, visited_at: string, client_ip: string, user_agent: string}}> */
    private array $consumable = [];

    /** @var list<string> */
    public array $acked = [];

    public function push(array $event): string
    {
        $id = (string) (count($this->consumable) + 1) . '-0';
        $this->events[] = $event;
        $this->consumable[] = [
            'id' => $id,
            'values' => $event,
        ];

        return $id;
    }

    public function consume(int $count = 100, int $blockMs = 1000): array
    {
        return array_splice($this->consumable, 0, $count);
    }

    public function ack(array $messageIds): void
    {
        foreach ($messageIds as $id) {
            $this->acked[] = $id;
        }
    }
}
