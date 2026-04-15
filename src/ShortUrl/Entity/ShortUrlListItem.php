<?php

declare(strict_types=1);

namespace SwooleLearn\ShortUrl\Entity;

use DateTimeImmutable;

final class ShortUrlListItem
{
    public function __construct(
        public readonly string $code,
        public readonly string $originalUrl,
        public readonly bool $isActive,
        public readonly ?DateTimeImmutable $expiresAt,
        public readonly int $totalVisits,
        public readonly ?DateTimeImmutable $lastVisitedAt,
        public readonly DateTimeImmutable $createdAt
    ) {
    }

    /**
     * @return array{
     *   code: string,
     *   original_url: string,
     *   is_active: bool,
     *   expires_at: string|null,
     *   total_visits: int,
     *   last_visited_at: string|null,
     *   created_at: string
     * }
     */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'original_url' => $this->originalUrl,
            'is_active' => $this->isActive,
            'expires_at' => $this->expiresAt?->format(DATE_ATOM),
            'total_visits' => $this->totalVisits,
            'last_visited_at' => $this->lastVisitedAt?->format(DATE_ATOM),
            'created_at' => $this->createdAt->format(DATE_ATOM),
        ];
    }
}
