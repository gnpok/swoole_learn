<?php

declare(strict_types=1);

namespace SwooleLearn\Tests;

use PHPUnit\Framework\TestCase;
use SwooleLearn\Learning\Chat\GroupRegistry;

final class ChatGroupRegistryTest extends TestCase
{
    public function test_join_leave_and_member_queries(): void
    {
        $registry = new GroupRegistry();

        $registry->join(1, 'g1');
        $registry->join(2, 'g1');
        $registry->join(2, 'g2');

        self::assertSame([1, 2], $registry->members('g1'));
        self::assertSame([2], $registry->members('g2'));
        self::assertSame(['g1', 'g2'], $registry->groupsOf(2));

        $registry->leave(2, 'g1');
        self::assertSame([1], $registry->members('g1'));

        $registry->removeFd(2);
        self::assertSame([], $registry->members('g2'));
        self::assertSame([], $registry->groupsOf(2));
    }
}
