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
use SwooleLearn\ShortUrl\Http\RequestContext;
use SwooleLearn\ShortUrl\Http\ShortUrlApiController;
use SwooleLearn\ShortUrl\Observability\CallableHealthReporter;
use SwooleLearn\ShortUrl\Observability\InMemoryPrometheusCollector;
use SwooleLearn\ShortUrl\Observability\LoggerInterface;
use SwooleLearn\ShortUrl\Service\ShortUrlService;

final class ShortUrlApiControllerTest extends TestCase
{
    public function test_create_endpoint_returns_201_with_payload(): void
    {
        $controller = $this->newController();
        $response = $controller->handle(new RequestContext(
            method: 'POST',
            path: '/api/v1/short-urls',
            body: ['url' => 'https://example.com/tutorial'],
            clientIp: '127.0.0.1'
        ));

        self::assertSame(201, $response->statusCode);
        $decoded = json_decode($response->body, true);

        self::assertIsArray($decoded);
        self::assertSame('https://example.com/tutorial', $decoded['data']['original_url'] ?? null);
        self::assertArrayHasKey('short_url', $decoded['data']);
    }

    public function test_create_endpoint_rejects_invalid_json_marker(): void
    {
        $controller = $this->newController();
        $response = $controller->handle(new RequestContext(
            method: 'POST',
            path: '/api/v1/short-urls',
            body: ['__invalid_json__' => true],
            clientIp: '127.0.0.1'
        ));

        self::assertSame(422, $response->statusCode);
    }

    public function test_redirect_endpoint_returns_location_header(): void
    {
        $controller = $this->newController();
        $createResponse = $controller->handle(new RequestContext(
            method: 'POST',
            path: '/api/v1/short-urls',
            body: ['url' => 'https://example.com/redirect'],
            clientIp: '127.0.0.1'
        ));
        $createPayload = json_decode($createResponse->body, true);
        $code = (string) ($createPayload['data']['code'] ?? '');

        $redirectResponse = $controller->handle(new RequestContext(
            method: 'GET',
            path: '/r/' . $code,
            clientIp: '127.0.0.1',
            userAgent: 'phpunit'
        ));

        self::assertSame(302, $redirectResponse->statusCode);
        self::assertSame('https://example.com/redirect', $redirectResponse->headers['Location'] ?? null);
    }

    public function test_detail_endpoint_returns_data_when_code_exists(): void
    {
        $controller = $this->newController();
        $createResponse = $controller->handle(new RequestContext(
            method: 'POST',
            path: '/api/v1/short-urls',
            body: ['url' => 'https://example.com/detail'],
            clientIp: '127.0.0.1'
        ));
        $createPayload = json_decode($createResponse->body, true);
        $code = (string) ($createPayload['data']['code'] ?? '');

        $detailResponse = $controller->handle(new RequestContext(
            method: 'GET',
            path: '/api/v1/short-urls/' . $code
        ));
        $detailPayload = json_decode($detailResponse->body, true);

        self::assertSame(200, $detailResponse->statusCode);
        self::assertSame($code, $detailPayload['data']['code'] ?? null);
    }

    public function test_unknown_route_returns_404(): void
    {
        $controller = $this->newController();
        $response = $controller->handle(new RequestContext('GET', '/api/v1/unknown'));

        self::assertSame(404, $response->statusCode);
    }

    public function test_create_with_idempotency_key_returns_same_short_code(): void
    {
        $controller = $this->newController();
        $context = new RequestContext(
            method: 'POST',
            path: '/api/v1/short-urls',
            body: ['url' => 'https://example.com/idem-api'],
            clientIp: '127.0.0.1',
            headers: ['idempotency-key' => 'idem-key-1']
        );

        $first = $controller->handle($context);
        $second = $controller->handle($context);

        $firstPayload = json_decode($first->body, true);
        $secondPayload = json_decode($second->body, true);

        self::assertSame(201, $first->statusCode);
        self::assertSame(201, $second->statusCode);
        self::assertSame($firstPayload['data']['code'], $secondPayload['data']['code']);
    }

    public function test_admin_list_and_bulk_disable_endpoints(): void
    {
        $controller = $this->newController();

        $firstCreate = $controller->handle(new RequestContext(
            method: 'POST',
            path: '/api/v1/short-urls',
            body: ['url' => 'https://example.com/admin-1'],
            clientIp: '127.0.0.1'
        ));
        $secondCreate = $controller->handle(new RequestContext(
            method: 'POST',
            path: '/api/v1/short-urls',
            body: ['url' => 'https://example.com/admin-2'],
            clientIp: '127.0.0.1'
        ));

        $firstCode = (string) ((json_decode($firstCreate->body, true)['data']['code']) ?? '');
        $secondCode = (string) ((json_decode($secondCreate->body, true)['data']['code']) ?? '');

        $listResponse = $controller->handle(new RequestContext(
            method: 'GET',
            path: '/api/v1/admin/short-urls',
            query: ['page' => 1, 'per_page' => 10, 'keyword' => 'admin'],
            headers: ['x-admin-api-key' => 'test-admin-key']
        ));
        $listPayload = json_decode($listResponse->body, true);

        self::assertSame(200, $listResponse->statusCode);
        self::assertGreaterThanOrEqual(2, $listPayload['data']['total'] ?? 0);

        $bulkResponse = $controller->handle(new RequestContext(
            method: 'POST',
            path: '/api/v1/admin/short-urls/bulk-disable',
            body: ['codes' => [$firstCode, $secondCode, 'nope1234']],
            headers: ['x-admin-api-key' => 'test-admin-key']
        ));
        $bulkPayload = json_decode($bulkResponse->body, true);

        self::assertSame(200, $bulkResponse->statusCode);
        self::assertSame(3, $bulkPayload['data']['requested'] ?? null);
        self::assertSame(2, $bulkPayload['data']['disabled'] ?? null);
        self::assertSame(['nope1234'], $bulkPayload['data']['missing'] ?? null);
    }

    public function test_admin_endpoints_require_valid_api_key(): void
    {
        $controller = $this->newController();

        $response = $controller->handle(new RequestContext(
            method: 'GET',
            path: '/api/v1/admin/short-urls'
        ));

        self::assertSame(401, $response->statusCode);
    }

    public function test_admin_endpoint_accepts_rotated_api_key_from_env_list(): void
    {
        putenv('ADMIN_API_KEYS=old-key,new-key');
        try {
            $controller = $this->newController(null);
            $response = $controller->handle(new RequestContext(
                method: 'GET',
                path: '/api/v1/admin/short-urls',
                headers: ['x-admin-api-key' => 'new-key']
            ));

            self::assertSame(200, $response->statusCode);
        } finally {
            putenv('ADMIN_API_KEYS');
        }
    }

    public function test_metrics_endpoint_exposes_prometheus_payload(): void
    {
        $metrics = new InMemoryPrometheusCollector();
        $controller = $this->newController('test-admin-key', $metrics);
        $controller->handle(new RequestContext(
            method: 'GET',
            path: '/api/v1/unknown',
            traceId: 'trace-metrics'
        ));

        $response = $controller->handle(new RequestContext(
            method: 'GET',
            path: '/metrics',
            traceId: 'trace-metrics'
        ));

        self::assertSame(200, $response->statusCode);
        self::assertStringContainsString('shorturl_http_requests_total', $response->body);
        self::assertSame('text/plain; version=0.0.4; charset=utf-8', $response->headers['Content-Type'] ?? null);
        self::assertSame('trace-metrics', $response->headers['X-Trace-Id'] ?? null);
    }

    public function test_readyz_returns_503_when_health_reporter_down(): void
    {
        $controller = $this->newController(
            'test-admin-key',
            null,
            new CallableHealthReporter([
                'mysql' => static fn (): array => ['status' => 'down', 'details' => ['reason' => 'connection refused']],
            ])
        );
        $response = $controller->handle(new RequestContext(
            method: 'GET',
            path: '/readyz',
            traceId: 'trace-readyz'
        ));
        $payload = json_decode($response->body, true);

        self::assertSame(503, $response->statusCode);
        self::assertSame('down', $payload['status'] ?? null);
        self::assertSame('trace-readyz', $response->headers['X-Trace-Id'] ?? null);
    }

    public function test_request_logs_emitted_with_trace_id(): void
    {
        $logger = new CollectingLogger();
        $controller = $this->newController('test-admin-key', null, null, $logger);
        $controller->handle(new RequestContext(
            method: 'GET',
            path: '/api/v1/unknown',
            traceId: 'trace-log'
        ));

        self::assertNotEmpty($logger->records);
        self::assertSame('trace-log', $logger->records[0]['context']['trace_id'] ?? null);
        self::assertSame('GET', $logger->records[0]['context']['method'] ?? null);
    }

    private function newController(
        ?string $adminApiKey = 'test-admin-key',
        ?InMemoryPrometheusCollector $metrics = null,
        ?CallableHealthReporter $healthReporter = null,
        ?LoggerInterface $logger = null
    ): ShortUrlApiController
    {
        $metrics ??= new InMemoryPrometheusCollector();
        $service = new ShortUrlService(
            repository: new ControllerFakeRepository(),
            cache: new ControllerFakeCache(),
            statsStore: new ControllerFakeStatsStore(),
            rateLimiter: new ControllerFakeRateLimiter(),
            codeGenerator: new ControllerFakeCodeGenerator(['qwerty1', 'qwerty2', 'qwerty3']),
            idempotencyStore: new ControllerFakeIdempotencyStore(),
            visitEventQueue: new ControllerFakeVisitEventQueue(),
            publicBaseUrl: 'http://127.0.0.1:9501',
            metrics: $metrics,
            logger: $logger
        );

        return new ShortUrlApiController($service, $adminApiKey, $metrics, $logger, $healthReporter);
    }
}

final class CollectingLogger implements LoggerInterface
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

final class ControllerFakeRepository implements ShortUrlRepositoryInterface
{
    /** @var array<string, ShortUrlRecord> */
    private array $records = [];

    private int $nextId = 1;

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
    }

    public function appendVisitLogsBatch(array $logs): int
    {
        return count($logs);
    }

    public function disable(string $code): bool
    {
        if (!isset($this->records[$code])) {
            return false;
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
            if ($record->isActive) {
                $disabled++;
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
        }

        return ['disabled' => $disabled, 'missing' => $missing];
    }
}

final class ControllerFakeCache implements ShortUrlCacheInterface
{
    /** @var array<string, ShortUrlRecord> */
    private array $records = [];

    public function get(string $code): ?ShortUrlRecord
    {
        return $this->records[$code] ?? null;
    }

    public function put(ShortUrlRecord $record, int $ttlSeconds): void
    {
        $this->records[$record->code] = $record;
    }

    public function forget(string $code): void
    {
        unset($this->records[$code]);
    }
}

final class ControllerFakeStatsStore implements StatsStoreInterface
{
    /** @var array<string, int> */
    private array $counter = [];

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
    }

    public function getRecentVisits(string $code, int $limit = 20): array
    {
        return [];
    }
}

final class ControllerFakeRateLimiter implements RateLimiterInterface
{
    public function hit(string $key, int $windowSeconds): int
    {
        return 1;
    }
}

final class ControllerFakeCodeGenerator implements ShortCodeGeneratorInterface
{
    /** @var list<string> */
    private array $codes;

    /**
     * @param list<string> $codes
     */
    public function __construct(array $codes)
    {
        $this->codes = $codes;
    }

    public function generate(int $length = 7): string
    {
        return array_shift($this->codes) ?? 'fallback1';
    }
}

final class ControllerFakeIdempotencyStore implements IdempotencyStoreInterface
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

final class ControllerFakeVisitEventQueue implements VisitEventQueueInterface
{
    public function push(array $event): string
    {
        return '1-0';
    }

    public function consume(int $count = 100, int $blockMs = 1000): array
    {
        return [];
    }

    public function reclaim(int $minIdleMs = 60000, int $count = 100): array
    {
        return [];
    }

    public function ack(array $messageIds): void
    {
    }

    public function retry(array $event, int $attempt, string $reason): string
    {
        return 'retry-1';
    }

    public function deadLetter(array $event, int $attempt, string $reason): string
    {
        return 'dead-1';
    }
}
