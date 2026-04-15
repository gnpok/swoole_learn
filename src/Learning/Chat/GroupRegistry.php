<?php

declare(strict_types=1);

namespace SwooleLearn\Learning\Chat;

final class GroupRegistry
{
    /** @var array<string, array<int, true>> */
    private array $groupMembers = [];

    /** @var array<int, array<string, true>> */
    private array $fdGroups = [];

    public function join(int $fd, string $groupId): void
    {
        $groupId = trim($groupId);
        if ($groupId === '') {
            return;
        }

        $this->groupMembers[$groupId] ??= [];
        $this->groupMembers[$groupId][$fd] = true;

        $this->fdGroups[$fd] ??= [];
        $this->fdGroups[$fd][$groupId] = true;
    }

    public function leave(int $fd, string $groupId): void
    {
        if (!isset($this->groupMembers[$groupId][$fd])) {
            return;
        }

        unset($this->groupMembers[$groupId][$fd]);
        if ($this->groupMembers[$groupId] === []) {
            unset($this->groupMembers[$groupId]);
        }

        unset($this->fdGroups[$fd][$groupId]);
        if (($this->fdGroups[$fd] ?? []) === []) {
            unset($this->fdGroups[$fd]);
        }
    }

    /**
     * @return list<int>
     */
    public function members(string $groupId): array
    {
        if (!isset($this->groupMembers[$groupId])) {
            return [];
        }

        return array_map(
            static fn (string|int $fd): int => (int) $fd,
            array_keys($this->groupMembers[$groupId])
        );
    }

    public function removeFd(int $fd): void
    {
        $groups = array_keys($this->fdGroups[$fd] ?? []);
        foreach ($groups as $groupId) {
            $this->leave($fd, $groupId);
        }
    }

    /**
     * @return list<string>
     */
    public function groupsOf(int $fd): array
    {
        return array_values(array_keys($this->fdGroups[$fd] ?? []));
    }
}
