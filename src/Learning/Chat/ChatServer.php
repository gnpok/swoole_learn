<?php

declare(strict_types=1);

namespace SwooleLearn\Learning\Chat;

use SwooleLearn\Learning\Contracts\WebSocketServerInterface;

final class ChatServer
{
    public function __construct(
        private readonly WebSocketServerInterface $server,
        private readonly ?ConnectionRegistry $connections = null,
        private readonly ?GroupRegistry $groups = null
    ) {
        $this->connectionRegistry = $connections ?? new ConnectionRegistry();
        $this->groupRegistry = $groups ?? new GroupRegistry();
    }

    private ConnectionRegistry $connectionRegistry;
    private GroupRegistry $groupRegistry;

    public function configure(array $settings = []): void
    {
        $defaults = [
            'worker_num' => 1,
            'daemonize' => false,
            'max_request' => 10000,
        ];

        $this->server->set(array_replace($defaults, $settings));
    }

    public function registerHandlers(): void
    {
        $this->server->on('open', function (object $request): void {
            $fd = (int) ($request->fd ?? 0);
            $query = property_exists($request, 'get') && is_array($request->get) ? $request->get : [];
            $uid = isset($query['uid']) ? trim((string) $query['uid']) : '';
            $groupId = isset($query['group_id']) ? trim((string) $query['group_id']) : '';

            if ($fd <= 0 || $uid === '' || $groupId === '') {
                if ($fd > 0) {
                    $this->server->push($fd, $this->json([
                        'event' => 'error',
                        'code' => 'INVALID_LOGIN',
                        'message' => 'uid and group_id are required.',
                    ]));
                    $this->server->disconnect($fd, 4000, 'invalid login');
                }

                return;
            }

            $this->handleLoginAction($fd, [
                'user_id' => $uid,
                'group_id' => $groupId,
            ]);
        });

        $this->server->on('message', function (object $frame): void {
            $fd = (int) ($frame->fd ?? 0);
            $raw = (string) ($frame->data ?? '');

            $payload = json_decode($raw, true);
            if (!is_array($payload)) {
                $this->server->push($fd, $this->json([
                    'event' => 'error',
                    'code' => 'INVALID_PAYLOAD',
                    'message' => 'payload must be json object.',
                ]));

                return;
            }

            $action = strtolower((string) ($payload['action'] ?? $payload['event'] ?? ''));
            if ($action === 'login') {
                $this->handleLoginAction($fd, $payload);
                return;
            }

            $session = $this->sessionByFd($fd);
            if ($session === null) {
                $this->server->push($fd, $this->json([
                    'event' => 'error',
                    'code' => 'UNAUTHORIZED',
                    'message' => 'please login first.',
                ]));

                return;
            }

            if ($action === 'group_message' || $action === 'chat') {
                $this->handleGroupMessageAction($fd, $session, $payload);
                return;
            }

            if ($action === 'switch_group') {
                $this->handleSwitchGroupAction($fd, $session, $payload);
                return;
            }

            $this->server->push($fd, $this->json([
                'event' => 'error',
                'code' => 'UNSUPPORTED_ACTION',
                'message' => 'action is not supported.',
            ]));
        });

        $this->server->on('close', function (...$args): void {
            if (count($args) === 1) {
                $fd = (int) $args[0];
            } else {
                $fd = (int) ($args[1] ?? 0);
            }

            $session = $this->connectionRegistry->unbindByFd($fd);
            if ($session === null) {
                return;
            }
            $userId = $session['uid'];

            $groupIds = $this->groupRegistry->groupsOf($fd);
            $this->groupRegistry->removeFd($fd);

            foreach ($groupIds as $groupId) {
                $this->broadcastGroup($groupId, [
                    'event' => 'member_left',
                    'uid' => $userId,
                    'fd' => $fd,
                    'group_id' => $groupId,
                ], $fd);
            }
        });
    }

    public function registerDefaultHandlers(): void
    {
        $this->registerHandlers();
    }

    public function start(): void
    {
        $this->server->start();
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function handleLoginAction(int $fd, array $payload): void
    {
        $uid = trim((string) ($payload['user_id'] ?? $payload['uid'] ?? ''));
        $groupId = trim((string) ($payload['group_id'] ?? ''));
        if ($uid === '' || $groupId === '') {
            $this->server->push($fd, $this->json([
                'event' => 'error',
                'code' => 'INVALID_LOGIN',
                'message' => 'user_id and group_id are required.',
            ]));
            return;
        }

        $kickFd = $this->connectionRegistry->bindUser($uid, $fd, $groupId);
        $this->groupRegistry->join($fd, $groupId);

        if ($kickFd !== null && $kickFd !== $fd && $this->server->exists($kickFd)) {
            foreach ($this->groupRegistry->groupsOf($kickFd) as $oldGroupId) {
                $this->groupRegistry->leave($kickFd, $oldGroupId);
            }

            $this->server->push($kickFd, $this->json([
                'event' => 'kicked',
                'reason' => 'duplicate_login',
                'uid' => $uid,
            ]));
            $this->server->disconnect($kickFd, 4001, 'duplicate login');
        }

        $this->server->push($fd, $this->json([
            'event' => 'login_success',
            'uid' => $uid,
            'group_id' => $groupId,
            'fd' => $fd,
        ]));

        $this->broadcastGroup($groupId, [
            'event' => 'member_joined',
            'uid' => $uid,
            'fd' => $fd,
            'group_id' => $groupId,
        ], $fd);
    }

    /**
     * @param array{uid: string, group_id: string} $session
     * @param array<string, mixed> $payload
     */
    private function handleGroupMessageAction(int $fd, array $session, array $payload): void
    {
        $message = trim((string) ($payload['content'] ?? $payload['message'] ?? ''));
        if ($message === '') {
            $this->server->push($fd, $this->json([
                'event' => 'error',
                'code' => 'EMPTY_MESSAGE',
                'message' => 'content is required.',
            ]));
            return;
        }

        $this->broadcastGroup($session['group_id'], [
            'event' => 'group_message',
            'uid' => $session['uid'],
            'from_fd' => $fd,
            'group_id' => $session['group_id'],
            'content' => $message,
        ]);
    }

    /**
     * @param array{uid: string, group_id: string} $session
     * @param array<string, mixed> $payload
     */
    private function handleSwitchGroupAction(int $fd, array $session, array $payload): void
    {
        $newGroupId = trim((string) ($payload['group_id'] ?? ''));
        if ($newGroupId === '') {
            $this->server->push($fd, $this->json([
                'event' => 'error',
                'code' => 'INVALID_GROUP',
                'message' => 'group_id is required.',
            ]));
            return;
        }

        $oldGroupId = $session['group_id'];
        if ($oldGroupId === $newGroupId) {
            $this->server->push($fd, $this->json([
                'event' => 'switch_group_skipped',
                'group_id' => $newGroupId,
            ]));
            return;
        }

        $this->groupRegistry->leave($fd, $oldGroupId);
        $this->groupRegistry->join($fd, $newGroupId);

        $this->server->push($fd, $this->json([
            'event' => 'switch_group_ok',
            'group_id' => $newGroupId,
            'old_group_id' => $oldGroupId,
        ]));

        $this->broadcastGroup($oldGroupId, [
            'event' => 'member_left',
            'uid' => $session['uid'],
            'fd' => $fd,
            'group_id' => $oldGroupId,
        ], $fd);

        $this->broadcastGroup($newGroupId, [
            'event' => 'member_joined',
            'uid' => $session['uid'],
            'fd' => $fd,
            'group_id' => $newGroupId,
        ], $fd);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function broadcastGroup(string $groupId, array $payload, ?int $excludeFd = null): void
    {
        foreach ($this->groupRegistry->members($groupId) as $fd) {
            if ($excludeFd !== null && $fd === $excludeFd) {
                continue;
            }

            if (!$this->server->exists($fd)) {
                continue;
            }

            $this->server->push($fd, $this->json($payload));
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function json(array $payload): string
    {
        return (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @return array{uid: string, group_id: string}|null
     */
    private function sessionByFd(int $fd): ?array
    {
        $uid = $this->connectionRegistry->getUserByFd($fd);
        if ($uid === null) {
            return null;
        }

        $groups = $this->groupRegistry->groupsOf($fd);
        $groupId = $groups[0] ?? null;
        if ($groupId === null) {
            return null;
        }

        return [
            'uid' => $uid,
            'group_id' => $groupId,
        ];
    }
}
