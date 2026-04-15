<?php

declare(strict_types=1);

namespace SwooleLearn\ShortUrl\Contracts;

use DateTimeImmutable;
use SwooleLearn\ShortUrl\Entity\ShortUrlPage;
use SwooleLearn\ShortUrl\Entity\ShortUrlRecord;

interface ShortUrlRepositoryInterface
{
    public function save(ShortUrlRecord $record): ShortUrlRecord;

    public function findByCode(string $code): ?ShortUrlRecord;

    public function existsByCode(string $code): bool;

    public function incrementVisits(string $code, DateTimeImmutable $visitedAt): void;

    public function appendVisitLog(string $code, DateTimeImmutable $visitedAt, string $clientIp, string $userAgent): void;

    public function disable(string $code): bool;

    public function paginate(int $page, int $pageSize, ?bool $isActive = null, ?string $keyword = null): ShortUrlPage;

    /**
     * @param list<string> $codes
     *
     * @return array{disabled: int, missing: list<string>}
     */
    public function bulkDisable(array $codes): array;
}
