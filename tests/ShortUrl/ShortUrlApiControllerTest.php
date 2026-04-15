<?php

declare(strict_types=1);

namespace SwooleLearn\Tests\ShortUrl;

use PHPUnit\Framework\TestCase;
use SwooleLearn\ShortUrl\Contracts\RateLimiterInterface;
use SwooleLearn\ShortUrl\Contracts\ShortCodeGeneratorInterface;
use SwooleLearn\ShortUrl\Contracts\ShortUrlCacheInterface;
use SwooleLearn\ShortUrl\Contracts\ShortUrlRepositoryInterface;
use SwooleLearn\ShortUrl\Contracts\StatsStoreInterface;
use SwooleLearn\ShortUrl\Entity\ShortUrlRecord;
use SwooleLearn\ShortUrl\Http\ShortUrlApiController;
use SwooleLearn\ShortUrl\Service\ShortUrlService;

final class ShortUrlApiControllerTest extends TestCase
{
    public function test_create_endpoint_returns_201_with_payload(): void
    {
        $controller = $this->newController();
        $response = $controller->handle(
            method: 'POST',
            path: '/api/v1/short-urls',
            body: ['url' => 'https://example.com/tutorial'],
            clientIp: '127.0.0.1'
        );

        self::assertSame(201, $response->statusCode);
        $decoded = json_decode($response->body, true);

        self::assertIsArray($decoded);
        self::assertSame('https://example.com/tutorial', $decoded['data']['original_url'] ?? null);
        self::assertArrayHasKey('short_url', $decoded['data']);
    }

    public function test_create_endpoint_rejects_invalid_json_marker(): void
    {
        $controller = $this->newController();
        $response = $controller->handle(
            method: 'POST',
            path: '/api/v1/short-urls',
            body: ['__invalid_json__' => true],
            clientIp: '127.0.0.1'
        );

        self::assertSame(422, $response->statusCode);
    }

    public function test_redirect_endpoint_returns_location_header(): void
    {
        $controller = $this->newController();
        $createResponse = $controller->handle(
            method: 'POST',
            path: '/api/v1/short-urls',
            body: ['url' => 'https://example.com/redirect'],
            clientIp: '127.0.0.1'
        );
        $createPayload = json_decode($createResponse->body, true);
        $code = (string) ($createPayload['data']['code'] ?? '');

        $redirectResponse = $controller->handle(
            method: 'GET',
            path: '/r/' . $code,
            clientIp: '127.0.0.1',
            userAgent: 'phpunit'
        );

        self::assertSame(302, $redirectResponse->statusCode);
        self::assertSame('https://example.com/redirect', $redirectResponse->headers['Location'] ?? null);
    }

    public function test_detail_endpoint_returns_data_when_code_exists(): void
    {
        $controller = $this->newController();
        $createResponse = $controller->handle(
            method: 'POST',
            path: '/api/v1/short-urls',
            body: ['url' => 'https://example.com/detail'],
            clientIp: '127.0.0.1'
        );
        $createPayload = json_decode($createResponse->body, true);
        $code = (string) ($createPayload['data']['code'] ?? '');

        $detailResponse = $controller->handle(
            method: 'GET',
            path: '/api/v1/short-urls/' . $code
        );
        $detailPayload = json_decode($detailResponse->body, true);

        self::assertSame(200, $detailResponse->statusCode);
        self::assertSame($code, $detailPayload['data']['code'] ?? null);
    }

    public function test_unknown_route_returns_404(): void
    {
        $controller = $this->newController();
        $response = $controller->handle('GET', '/api/v1/unknown');

        self::assertSame(404, $response->statusCode);
    }

    private function newController(): ShortUrlApiController
    {
        $service = new ShortUrlService(
            repository: new ControllerFakeRepository(),
            cache: new ControllerFakeCache(),
            statsStore: new ControllerFakeStatsStore(),
            rateLimiter: new ControllerFakeRateLimiter(),
            codeGenerator: new ControllerFakeCodeGenerator(['qwerty1', 'qwerty2', 'qwerty3']),
            publicBaseUrl: 'http://127.0.0.1:9501'
        );

        return new ShortUrlApiController($service);
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

    public function incrementVisits(string $code, \DateTimeImmutable $visitedAt): void
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

    public function appendVisitLog(string $code, \DateTimeImmutable $visitedAt, string $clientIp, string $userAgent): void
    {
    }

    public function disable(string $code): bool
    {
        if (!isset($this->records[$code])) {
            return false;
        }

        unset($this->records[$code]);

        return true;
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
