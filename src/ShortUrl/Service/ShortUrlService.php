<?php

declare(strict_types=1);

namespace SwooleLearn\ShortUrl\Service;

use Closure;
use DateTimeImmutable;
use SwooleLearn\ShortUrl\Contracts\RateLimiterInterface;
use SwooleLearn\ShortUrl\Contracts\ShortCodeGeneratorInterface;
use SwooleLearn\ShortUrl\Contracts\ShortUrlCacheInterface;
use SwooleLearn\ShortUrl\Contracts\IdempotencyStoreInterface;
use SwooleLearn\ShortUrl\Contracts\ShortUrlRepositoryInterface;
use SwooleLearn\ShortUrl\Contracts\StatsStoreInterface;
use SwooleLearn\ShortUrl\Contracts\VisitEventQueueInterface;
use SwooleLearn\ShortUrl\Entity\ShortUrlListItem;
use SwooleLearn\ShortUrl\Entity\ShortUrlRecord;
use SwooleLearn\ShortUrl\Exception\ConflictShortCodeException;
use SwooleLearn\ShortUrl\Exception\InactiveShortUrlException;
use SwooleLearn\ShortUrl\Exception\NotFoundException;
use SwooleLearn\ShortUrl\Exception\RateLimitException;
use SwooleLearn\ShortUrl\Exception\ValidationException;
use SwooleLearn\ShortUrl\Observability\LoggerInterface;
use SwooleLearn\ShortUrl\Observability\MetricsCollectorInterface;
use SwooleLearn\ShortUrl\Observability\NullLogger;
use SwooleLearn\ShortUrl\Observability\NullMetricsCollector;
use Throwable;

final class ShortUrlService
{
    private const CODE_PATTERN = '/^[A-Za-z0-9_-]{4,16}$/';
    private const CACHE_TTL_SECONDS = 86400;
    private const MAX_GENERATE_ATTEMPTS = 12;

    private Closure $clock;
    private MetricsCollectorInterface $metricsCollector;
    private LoggerInterface $logger;

    public function __construct(
        private readonly ShortUrlRepositoryInterface $repository,
        private readonly ShortUrlCacheInterface $cache,
        private readonly StatsStoreInterface $statsStore,
        private readonly RateLimiterInterface $rateLimiter,
        private readonly ShortCodeGeneratorInterface $codeGenerator,
        private readonly IdempotencyStoreInterface $idempotencyStore,
        private readonly VisitEventQueueInterface $visitEventQueue,
        private readonly string $publicBaseUrl,
        ?callable $clock = null,
        private readonly int $createLimit = 30,
        private readonly int $createWindowSeconds = 60,
        ?MetricsCollectorInterface $metrics = null,
        ?LoggerInterface $logger = null
    ) {
        $this->clock = $clock instanceof Closure
            ? $clock
            : Closure::fromCallable($clock ?? static fn (): DateTimeImmutable => new DateTimeImmutable());
        $this->metricsCollector = $metrics ?? new NullMetricsCollector();
        $this->logger = $logger ?? new NullLogger();
    }

    public function create(
        string $originalUrl,
        ?string $customCode = null,
        ?DateTimeImmutable $expiresAt = null,
        string $clientIp = '0.0.0.0'
    ): ShortUrlRecord {
        $startedAtNs = hrtime(true);
        try {
            $this->assertCreateRateLimit($clientIp);

            $originalUrl = trim($originalUrl);
            $this->assertValidUrl($originalUrl);

            $now = ($this->clock)();
            if ($expiresAt !== null && $expiresAt <= $now) {
                throw new ValidationException('expires_at must be a future datetime.');
            }

            $code = $customCode === null || trim($customCode) === ''
                ? $this->generateUniqueCode()
                : $this->useCustomCode($customCode);

            $saved = $this->repository->save(new ShortUrlRecord(
                id: null,
                code: $code,
                originalUrl: $originalUrl,
                createdAt: $now,
                expiresAt: $expiresAt,
                isActive: true,
                totalVisits: 0,
                lastVisitedAt: null
            ));

            $this->cache->put($saved, $this->calculateTtl($saved, $now));
            $this->metricsCollector->incrementCounter(
                name: 'shorturl_service_create_total',
                labels: ['result' => 'success'],
                help: 'Create short URL attempts grouped by result.'
            );

            return $saved;
        } catch (Throwable $throwable) {
            $this->metricsCollector->incrementCounter(
                name: 'shorturl_service_create_total',
                labels: ['result' => 'error', 'error_type' => $throwable::class],
                help: 'Create short URL attempts grouped by result.'
            );
            $this->logger->warning('Short URL create failed', [
                'client_ip' => $clientIp,
                'error' => $throwable->getMessage(),
                'error_type' => $throwable::class,
            ]);
            throw $throwable;
        } finally {
            $this->metricsCollector->observeHistogram(
                name: 'shorturl_service_create_duration_seconds',
                value: (hrtime(true) - $startedAtNs) / 1_000_000_000,
                help: 'Duration histogram for create short URL operation.'
            );
        }
    }

    public function createIdempotent(
        string $idempotencyKey,
        string $originalUrl,
        ?string $customCode = null,
        ?DateTimeImmutable $expiresAt = null,
        string $clientIp = '0.0.0.0'
    ): ShortUrlRecord {
        $normalizedKey = trim($idempotencyKey);
        if ($normalizedKey === '') {
            throw new ValidationException('Idempotency-Key header is required.');
        }

        $fullKey = 'create:' . $normalizedKey;
        $existingCode = $this->idempotencyStore->get($fullKey);
        if ($existingCode !== null && $existingCode !== '') {
            return $this->loadRecord($this->normalizeCode($existingCode));
        }

        $created = $this->create(
            originalUrl: $originalUrl,
            customCode: $customCode,
            expiresAt: $expiresAt,
            clientIp: $clientIp
        );

        $claimed = $this->idempotencyStore->claim($fullKey, $created->code, 86400);
        if (!$claimed) {
            $existingCode = $this->idempotencyStore->get($fullKey);
            if ($existingCode !== null && $existingCode !== '') {
                return $this->loadRecord($this->normalizeCode($existingCode));
            }
        }

        return $created;
    }

    public function resolve(string $code, string $clientIp, string $userAgent = ''): ShortUrlRecord
    {
        $startedAtNs = hrtime(true);
        try {
            $record = $this->loadRecord($this->normalizeCode($code));
            $now = ($this->clock)();

            if (!$record->isActive) {
                throw new InactiveShortUrlException('Short URL has been disabled.');
            }

            if ($record->isExpired($now)) {
                throw new InactiveShortUrlException('Short URL has expired.');
            }

            $this->repository->incrementVisits($record->code, $now);
            $eventKey = $this->buildVisitEventKey($record->code, $now, $clientIp, $userAgent);
            $visitPayload = [
                'short_url_code' => $record->code,
                'visited_at' => $now->format(DATE_ATOM),
                'client_ip' => $clientIp,
                'user_agent' => $userAgent,
                'event_key' => $eventKey,
                'attempt' => 1,
            ];
            $this->visitEventQueue->push($visitPayload);
            $this->statsStore->increment($record->code);
            $this->statsStore->addRecentVisit($record->code, [
                'visited_at' => $now->format(DATE_ATOM),
                'client_ip' => $clientIp,
                'user_agent' => $userAgent,
            ]);

            $updated = $record->withVisit($now);
            $this->cache->put($updated, $this->calculateTtl($updated, $now));
            $this->metricsCollector->incrementCounter(
                name: 'shorturl_service_resolve_total',
                labels: ['result' => 'success'],
                help: 'Resolve short URL attempts grouped by result.'
            );

            return $updated;
        } catch (Throwable $throwable) {
            $this->metricsCollector->incrementCounter(
                name: 'shorturl_service_resolve_total',
                labels: ['result' => 'error', 'error_type' => $throwable::class],
                help: 'Resolve short URL attempts grouped by result.'
            );
            $this->logger->warning('Short URL resolve failed', [
                'code' => $code,
                'client_ip' => $clientIp,
                'error' => $throwable->getMessage(),
                'error_type' => $throwable::class,
            ]);
            throw $throwable;
        } finally {
            $this->metricsCollector->observeHistogram(
                name: 'shorturl_service_resolve_duration_seconds',
                value: (hrtime(true) - $startedAtNs) / 1_000_000_000,
                help: 'Duration histogram for resolve operation.'
            );
        }
    }

    /**
     * @return array{
     *   code: string,
     *   short_url: string,
     *   original_url: string,
     *   created_at: string,
     *   expires_at: string|null,
     *   is_active: bool,
     *   total_visits: int,
     *   last_visited_at: string|null,
     *   recent_visits: list<array{visited_at: string, client_ip: string, user_agent: string}>
     * }
     */
    public function getDetail(string $code): array
    {
        $record = $this->loadRecord($this->normalizeCode($code));
        $redisCounter = $this->statsStore->getTotal($record->code);
        $recentVisits = $this->statsStore->getRecentVisits($record->code, 20);

        return [
            'code' => $record->code,
            'short_url' => $this->buildShortUrl($record->code),
            'original_url' => $record->originalUrl,
            'created_at' => $record->createdAt->format(DATE_ATOM),
            'expires_at' => $record->expiresAt?->format(DATE_ATOM),
            'is_active' => $record->isActive,
            'total_visits' => max($record->totalVisits, $redisCounter),
            'last_visited_at' => $record->lastVisitedAt?->format(DATE_ATOM),
            'recent_visits' => $recentVisits,
        ];
    }

    public function disable(string $code): void
    {
        $normalizedCode = $this->normalizeCode($code);

        if (!$this->repository->disable($normalizedCode)) {
            throw new NotFoundException('Short URL not found.');
        }

        $this->cache->forget($normalizedCode);
    }

    /**
     * @return array{items: list<array{
     *   code: string,
     *   short_url: string,
     *   original_url: string,
     *   is_active: bool,
     *   total_visits: int,
     *   created_at: string,
     *   expires_at: string|null,
     *   last_visited_at: string|null
     * }>, page: int, per_page: int, total: int}
     */
    public function listShortUrls(int $page = 1, int $perPage = 20, ?bool $isActive = null, ?string $keyword = null): array
    {
        $safePage = max(1, $page);
        $safePerPage = max(1, min(100, $perPage));
        $safeKeyword = $keyword === null ? null : trim($keyword);

        $result = $this->repository->paginate($safePage, $safePerPage, $isActive, $safeKeyword);

        return [
            'items' => array_map(
                fn (ShortUrlListItem $item): array => [
                    'code' => $item->code,
                    'short_url' => $this->buildShortUrl($item->code),
                    'original_url' => $item->originalUrl,
                    'is_active' => $item->isActive,
                    'total_visits' => $item->totalVisits,
                    'created_at' => $item->createdAt->format(DATE_ATOM),
                    'expires_at' => $item->expiresAt?->format(DATE_ATOM),
                    'last_visited_at' => $item->lastVisitedAt?->format(DATE_ATOM),
                ],
                $result->items
            ),
            'page' => $result->page,
            'per_page' => $result->perPage,
            'total' => $result->total,
        ];
    }

    /**
     * @param list<string> $codes
     *
     * @return array{requested: int, disabled: int, missing: list<string>}
     */
    public function bulkDisable(array $codes): array
    {
        $normalized = [];
        foreach ($codes as $code) {
            $normalized[] = $this->normalizeCode((string) $code);
        }
        $normalized = array_values(array_unique($normalized));

        if ($normalized === []) {
            throw new ValidationException('codes must contain at least one short code.');
        }

        $bulkResult = $this->repository->bulkDisable($normalized);
        foreach ($normalized as $code) {
            $this->cache->forget($code);
        }

        return [
            'requested' => count($normalized),
            'disabled' => $bulkResult['disabled'],
            'missing' => $bulkResult['missing'],
        ];
    }

    public function buildShortUrl(string $code): string
    {
        return rtrim($this->publicBaseUrl, '/') . '/r/' . $code;
    }

    private function assertCreateRateLimit(string $clientIp): void
    {
        $counter = $this->rateLimiter->hit('create:' . $clientIp, $this->createWindowSeconds);

        if ($counter > $this->createLimit) {
            throw new RateLimitException(sprintf(
                'Too many create requests from %s. Limit %d per %d seconds.',
                $clientIp,
                $this->createLimit,
                $this->createWindowSeconds
            ));
        }
    }

    private function assertValidUrl(string $originalUrl): void
    {
        if ($originalUrl === '' || filter_var($originalUrl, FILTER_VALIDATE_URL) === false) {
            throw new ValidationException('url must be a valid URL.');
        }

        $scheme = parse_url($originalUrl, PHP_URL_SCHEME);
        if (!is_string($scheme) || !in_array(strtolower($scheme), ['http', 'https'], true)) {
            throw new ValidationException('Only http/https URLs are supported.');
        }
    }

    private function useCustomCode(string $customCode): string
    {
        $code = $this->normalizeCode($customCode);

        if ($this->repository->existsByCode($code)) {
            throw new ConflictShortCodeException(sprintf('Code "%s" already exists.', $code));
        }

        return $code;
    }

    private function generateUniqueCode(): string
    {
        for ($i = 0; $i < self::MAX_GENERATE_ATTEMPTS; $i++) {
            $candidate = $this->normalizeCode($this->codeGenerator->generate());
            if (!$this->repository->existsByCode($candidate)) {
                return $candidate;
            }
        }

        throw new ConflictShortCodeException('Unable to allocate short code after multiple attempts.');
    }

    private function normalizeCode(string $code): string
    {
        $normalizedCode = trim($code);

        if (!preg_match(self::CODE_PATTERN, $normalizedCode)) {
            throw new ValidationException('code must match /^[A-Za-z0-9_-]{4,16}$/');
        }

        return $normalizedCode;
    }

    private function loadRecord(string $code): ShortUrlRecord
    {
        $cached = $this->cache->get($code);
        if ($cached !== null) {
            $this->metricsCollector->incrementCounter(
                name: 'shorturl_cache_lookup_total',
                labels: ['result' => 'hit'],
                help: 'Short URL metadata cache lookup result.'
            );
            return $cached;
        }
        $this->metricsCollector->incrementCounter(
            name: 'shorturl_cache_lookup_total',
            labels: ['result' => 'miss'],
            help: 'Short URL metadata cache lookup result.'
        );

        $record = $this->repository->findByCode($code);
        if ($record === null) {
            throw new NotFoundException('Short URL not found.');
        }

        $this->cache->put($record, $this->calculateTtl($record, ($this->clock)()));

        return $record;
    }

    private function calculateTtl(ShortUrlRecord $record, DateTimeImmutable $now): int
    {
        if ($record->expiresAt === null) {
            return self::CACHE_TTL_SECONDS;
        }

        $ttl = $record->expiresAt->getTimestamp() - $now->getTimestamp();
        if ($ttl <= 0) {
            return 60;
        }

        return min(self::CACHE_TTL_SECONDS, $ttl);
    }

    private function buildVisitEventKey(
        string $code,
        DateTimeImmutable $visitedAt,
        string $clientIp,
        string $userAgent
    ): string {
        return hash(
            'sha256',
            implode('|', [$code, $visitedAt->format(DATE_ATOM), $clientIp, $userAgent])
        );
    }
}
