<?php

declare(strict_types=1);

namespace SwooleLearn\Tests;

use PHPUnit\Framework\TestCase;
use SwooleLearn\Learning\Chat\ConnectionRegistry;

final class ChatConnectionRegistryTest extends TestCase
{
    public function test_bind_user_returns_previous_fd_when_duplicate_login_happens(): void
    {
        $registry = new ConnectionRegistry();

        $firstPrevious = $registry->bindUser('user-1', 1001, 'g-1');
        $secondPrevious = $registry->bindUser('user-1', 1002, 'g-2');

        self::assertNull($firstPrevious);
        self::assertSame(1001, $secondPrevious);
        self::assertSame([
            'uid' => 'user-1',
            'group_id' => 'g-2',
        ], $registry->getByFd(1002));
        self::assertNull($registry->getByFd(1001));
    }

    public function test_remove_by_fd_clears_bidirectional_mapping(): void
    {
        $registry = new ConnectionRegistry();
        $registry->bindUser('user-2', 2001, 'g-2');

        $removedSession = $registry->unbindByFd(2001);

        self::assertSame([
            'uid' => 'user-2',
            'group_id' => 'g-2',
        ], $removedSession);
        self::assertNull($registry->getFdByUser('user-2'));
        self::assertNull($registry->getByFd(2001));
    }

    public function test_snapshot_contains_current_online_mapping(): void
    {
        $registry = new ConnectionRegistry();
        $registry->bindUser('alice', 3001, 'ga');
        $registry->bindUser('bob', 3002, 'gb');

        $snapshot = $registry->snapshot();
        ksort($snapshot);

        self::assertSame([
            'alice' => 3001,
            'bob' => 3002,
        ], $snapshot);
    }
}
