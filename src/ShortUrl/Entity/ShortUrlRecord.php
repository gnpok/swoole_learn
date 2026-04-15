<?php

declare(strict_types=1);

namespace SwooleLearn\ShortUrl\Entity;

use DateTimeImmutable;
use InvalidArgumentException;

final class ShortUrlRecord
{
    public function __construct(
        public readonly ?int $id,
        public readonly string $code,
        public readonly string $originalUrl,
        public readonly DateTimeImmutable $createdAt,
        public readonly ?DateTimeImmutable $expiresAt,
        public readonly bool $isActive,
        public readonly int $totalVisits,
        public readonly ?DateTimeImmutable $lastVisitedAt
    ) {
    }

    public function isExpired(DateTimeImmutable $now): bool
    {
        return $this->expiresAt !== null && $this->expiresAt <= $now;
    }

    public function isAccessible(DateTimeImmutable $now): bool
    {
        return $this->isActive && !$this->isExpired($now);
    }

    public function withVisit(DateTimeImmutable $visitedAt): self
    {
        return new self(
            $this->id,
            $this->code,
            $this->originalUrl,
            $this->createdAt,
            $this->expiresAt,
            $this->isActive,
            $this->totalVisits + 1,
            $visitedAt
        );
    }

    public function toCachePayload(): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'original_url' => $this->originalUrl,
            'created_at' => $this->createdAt->format(DATE_ATOM),
            'expires_at' => $this->expiresAt?->format(DATE_ATOM),
            'is_active' => $this->isActive,
            'total_visits' => $this->totalVisits,
            'last_visited_at' => $this->lastVisitedAt?->format(DATE_ATOM),
        ];
    }

    public static function fromCachePayload(array $payload): self
    {
        if (!isset($payload['code'], $payload['original_url'], $payload['created_at'])) {
            throw new InvalidArgumentException('Invalid short url cache payload.');
        }

        return new self(
            isset($payload['id']) ? (int) $payload['id'] : null,
            (string) $payload['code'],
            (string) $payload['original_url'],
            new DateTimeImmutable((string) $payload['created_at']),
            isset($payload['expires_at']) && $payload['expires_at'] !== null
                ? new DateTimeImmutable((string) $payload['expires_at'])
                : null,
            (bool) ($payload['is_active'] ?? true),
            (int) ($payload['total_visits'] ?? 0),
            isset($payload['last_visited_at']) && $payload['last_visited_at'] !== null
                ? new DateTimeImmutable((string) $payload['last_visited_at'])
                : null
        );
    }
}
