<?php

declare(strict_types=1);

namespace SwooleLearn\Learning\Chat;

final class ConnectionRegistry
{
    /** @var array<int, array{uid: string, group_id: string}> */
    private array $fdSessions = [];

    /** @var array<string, int> */
    private array $uidToFd = [];

    /**
     * Bind user and group to fd.
     *
     * @return int|null previous fd for this uid
     */
    public function bindUser(string $uid, int $fd, string $groupId): ?int
    {
        $uid = trim($uid);
        $groupId = trim($groupId);
        $previousFd = $this->uidToFd[$uid] ?? null;

        if ($previousFd !== null && $previousFd !== $fd) {
            unset($this->fdSessions[$previousFd]);
        }

        $previousSession = $this->fdSessions[$fd] ?? null;
        if ($previousSession !== null && $previousSession['uid'] !== $uid) {
            unset($this->uidToFd[$previousSession['uid']]);
        }

        $this->fdSessions[$fd] = [
            'uid' => $uid,
            'group_id' => $groupId,
        ];
        $this->uidToFd[$uid] = $fd;

        return $previousFd;
    }

    /**
     * @return array{uid: string, group_id: string}|null
     */
    public function getByFd(int $fd): ?array
    {
        return $this->fdSessions[$fd] ?? null;
    }

    public function getUserByFd(int $fd): ?string
    {
        return $this->fdSessions[$fd]['uid'] ?? null;
    }

    public function getFdByUser(string $uid): ?int
    {
        return $this->uidToFd[$uid] ?? null;
    }

    /**
     * @return array{uid: string, group_id: string}|null
     */
    public function unbindByFd(int $fd): ?array
    {
        $session = $this->fdSessions[$fd] ?? null;
        if ($session === null) {
            return null;
        }

        unset($this->fdSessions[$fd]);
        if (($this->uidToFd[$session['uid']] ?? null) === $fd) {
            unset($this->uidToFd[$session['uid']]);
        }

        return $session;
    }

    public function switchGroup(int $fd, string $newGroupId): void
    {
        if (!isset($this->fdSessions[$fd])) {
            return;
        }

        $this->fdSessions[$fd]['group_id'] = trim($newGroupId);
    }

    /**
     * @return array<string, int>
     */
    public function snapshot(): array
    {
        return $this->uidToFd;
    }
}
