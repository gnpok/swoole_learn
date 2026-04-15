<?php

declare(strict_types=1);

namespace SwooleLearn\ShortUrl\Service;

use Closure;
use DateTimeImmutable;
use SwooleLearn\ShortUrl\Contracts\RateLimiterInterface;
use SwooleLearn\ShortUrl\Contracts\ShortCodeGeneratorInterface;
use SwooleLearn\ShortUrl\Contracts\ShortUrlCacheInterface;
use SwooleLearn\ShortUrl\Contracts\ShortUrlRepositoryInterface;
use SwooleLearn\ShortUrl\Contracts\StatsStoreInterface;
use SwooleLearn\ShortUrl\Entity\ShortUrlRecord;
use SwooleLearn\ShortUrl\Exception\ConflictException;
use SwooleLearn\ShortUrl\Exception\InactiveShortUrlException;
use SwooleLearn\ShortUrl\Exception\NotFoundException;
use SwooleLearn\ShortUrl\Exception\RateLimitException;
use SwooleLearn\ShortUrl\Exception\ValidationException;

final class ShortUrlService
{
    private const CODE_PATTERN = '/^[A-Za-z0-9_-]{4,16}$/';
    private const CACHE_TTL_SECONDS = 86400;
    private const MAX_GENERATE_ATTEMPTS = 12;

    private Closure $clock;

    public function __construct(
        private readonly ShortUrlRepositoryInterface $repository,
        private readonly ShortUrlCacheInterface $cache,
        private readonly StatsStoreInterface $statsStore,
        private readonly RateLimiterInterface $rateLimiter,
        private readonly ShortCodeGeneratorInterface $codeGenerator,
        private readonly string $publicBaseUrl,
        ?callable $clock = null,
        private readonly int $createLimit = 30,
        private readonly int $createWindowSeconds = 60
    ) {
        $this->clock = $clock instanceof Closure
            ? $clock
            : Closure::fromCallable($clock ?? static fn (): DateTimeImmutable => new DateTimeImmutable());
    }

    public function create(
        string $originalUrl,
        ?string $customCode = null,
        ?DateTimeImmutable $expiresAt = null,
        string $clientIp = '0.0.0.0'
    ): ShortUrlRecord {
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

        return $saved;
    }

    public function resolve(string $code, string $clientIp, string $userAgent = ''): ShortUrlRecord
    {
        $record = $this->loadRecord($this->normalizeCode($code));
        $now = ($this->clock)();

        if (!$record->isActive) {
            throw new InactiveShortUrlException('Short URL has been disabled.');
        }

        if ($record->isExpired($now)) {
            throw new InactiveShortUrlException('Short URL has expired.');
        }

        $this->repository->incrementVisits($record->code, $now);
        $this->repository->appendVisitLog($record->code, $now, $clientIp, $userAgent);
        $this->statsStore->increment($record->code);
        $this->statsStore->addRecentVisit($record->code, [
            'visited_at' => $now->format(DATE_ATOM),
            'client_ip' => $clientIp,
            'user_agent' => $userAgent,
        ]);

        $updated = $record->withVisit($now);
        $this->cache->put($updated, $this->calculateTtl($updated, $now));

        return $updated;
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
            throw new ConflictException(sprintf('Code "%s" already exists.', $code));
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

        throw new ConflictException('Unable to allocate short code after multiple attempts.');
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
            return $cached;
        }

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
}
