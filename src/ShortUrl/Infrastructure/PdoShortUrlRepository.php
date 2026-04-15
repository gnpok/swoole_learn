<?php

declare(strict_types=1);

namespace SwooleLearn\ShortUrl\Infrastructure;

use DateTimeImmutable;
use PDO;
use SwooleLearn\ShortUrl\Contracts\ShortUrlRepositoryInterface;
use SwooleLearn\ShortUrl\Entity\ShortUrlRecord;

final class PdoShortUrlRepository implements ShortUrlRepositoryInterface
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function save(ShortUrlRecord $record): ShortUrlRecord
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO short_urls 
                (code, original_url, is_active, expires_at, total_visits, last_visited_at, created_at, updated_at)
             VALUES 
                (:code, :original_url, :is_active, :expires_at, :total_visits, :last_visited_at, :created_at, :updated_at)'
        );

        $createdAt = $record->createdAt->format('Y-m-d H:i:s');
        $statement->execute([
            ':code' => $record->code,
            ':original_url' => $record->originalUrl,
            ':is_active' => $record->isActive ? 1 : 0,
            ':expires_at' => $record->expiresAt?->format('Y-m-d H:i:s'),
            ':total_visits' => $record->totalVisits,
            ':last_visited_at' => $record->lastVisitedAt?->format('Y-m-d H:i:s'),
            ':created_at' => $createdAt,
            ':updated_at' => $createdAt,
        ]);

        return new ShortUrlRecord(
            id: (int) $this->pdo->lastInsertId(),
            code: $record->code,
            originalUrl: $record->originalUrl,
            createdAt: $record->createdAt,
            expiresAt: $record->expiresAt,
            isActive: $record->isActive,
            totalVisits: $record->totalVisits,
            lastVisitedAt: $record->lastVisitedAt
        );
    }

    public function findByCode(string $code): ?ShortUrlRecord
    {
        $statement = $this->pdo->prepare(
            'SELECT id, code, original_url, is_active, expires_at, total_visits, last_visited_at, created_at 
             FROM short_urls 
             WHERE code = :code 
             LIMIT 1'
        );
        $statement->execute([':code' => $code]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            return null;
        }

        return new ShortUrlRecord(
            id: (int) $row['id'],
            code: (string) $row['code'],
            originalUrl: (string) $row['original_url'],
            createdAt: new DateTimeImmutable((string) $row['created_at']),
            expiresAt: $row['expires_at'] === null ? null : new DateTimeImmutable((string) $row['expires_at']),
            isActive: ((int) $row['is_active']) === 1,
            totalVisits: (int) $row['total_visits'],
            lastVisitedAt: $row['last_visited_at'] === null ? null : new DateTimeImmutable((string) $row['last_visited_at'])
        );
    }

    public function existsByCode(string $code): bool
    {
        $statement = $this->pdo->prepare('SELECT 1 FROM short_urls WHERE code = :code LIMIT 1');
        $statement->execute([':code' => $code]);

        return $statement->fetchColumn() !== false;
    }

    public function incrementVisits(string $code, DateTimeImmutable $visitedAt): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE short_urls 
             SET total_visits = total_visits + 1, last_visited_at = :visited_at, updated_at = :updated_at 
             WHERE code = :code'
        );

        $formatted = $visitedAt->format('Y-m-d H:i:s');
        $statement->execute([
            ':code' => $code,
            ':visited_at' => $formatted,
            ':updated_at' => $formatted,
        ]);
    }

    public function appendVisitLog(string $code, DateTimeImmutable $visitedAt, string $clientIp, string $userAgent): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO short_url_visits (short_url_code, visited_at, client_ip, user_agent)
             VALUES (:short_url_code, :visited_at, :client_ip, :user_agent)'
        );
        $statement->execute([
            ':short_url_code' => $code,
            ':visited_at' => $visitedAt->format('Y-m-d H:i:s'),
            ':client_ip' => $clientIp,
            ':user_agent' => mb_substr($userAgent, 0, 255),
        ]);
    }

    public function disable(string $code): bool
    {
        $statement = $this->pdo->prepare(
            'UPDATE short_urls SET is_active = 0, updated_at = :updated_at WHERE code = :code'
        );
        $statement->execute([
            ':code' => $code,
            ':updated_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);

        return $statement->rowCount() > 0;
    }
}
