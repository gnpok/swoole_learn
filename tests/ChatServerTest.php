<?php

declare(strict_types=1);

namespace SwooleLearn\Tests;

use PHPUnit\Framework\TestCase;
use SwooleLearn\Learning\Chat\ChatServer;
use SwooleLearn\Learning\Contracts\WebSocketServerInterface;

final class ChatServerTest extends TestCase
{
    public function test_duplicate_login_kicks_old_connection_and_rebinds_user(): void
    {
        $server = new FakeWebSocketServer();
        $chat = new ChatServer($server);
        $chat->configure();
        $chat->registerHandlers();

        self::assertArrayHasKey('message', $server->handlers);
        $onMessage = $server->handlers['message'];

        $onMessage(new FakeWebSocketFrame(11, json_encode([
            'action' => 'login',
            'user_id' => 'u-1',
            'group_id' => 'g-1',
        ])));
        $onMessage(new FakeWebSocketFrame(12, json_encode([
            'action' => 'login',
            'user_id' => 'u-1',
            'group_id' => 'g-2',
        ])));

        self::assertTrue($server->disconnected[11] ?? false);
        self::assertArrayHasKey(12, $server->pushed);
        self::assertStringContainsString('"event":"login_success"', $server->latestPushBody(12) ?? '');
        self::assertStringContainsString('"group_id":"g-2"', $server->latestPushBody(12) ?? '');
    }

    public function test_group_message_is_broadcast_to_same_group_only(): void
    {
        $server = new FakeWebSocketServer();
        $chat = new ChatServer($server);
        $chat->configure();
        $chat->registerHandlers();

        $onMessage = $server->handlers['message'];

        $onMessage(new FakeWebSocketFrame(21, json_encode([
            'action' => 'login',
            'user_id' => 'u-21',
            'group_id' => 'g-1',
        ])));
        $onMessage(new FakeWebSocketFrame(22, json_encode([
            'action' => 'login',
            'user_id' => 'u-22',
            'group_id' => 'g-1',
        ])));
        $onMessage(new FakeWebSocketFrame(23, json_encode([
            'action' => 'login',
            'user_id' => 'u-23',
            'group_id' => 'g-2',
        ])));

        $onMessage(new FakeWebSocketFrame(21, json_encode([
            'action' => 'group_message',
            'content' => 'hello group one',
        ])));

        self::assertStringContainsString('"event":"group_message"', $server->latestPushBody(21) ?? '');
        self::assertStringContainsString('"event":"group_message"', $server->latestPushBody(22) ?? '');
        self::assertStringNotContainsString('"event":"group_message"', $server->latestPushBody(23) ?? '');
    }

    public function test_close_event_removes_fd_from_group_and_registry(): void
    {
        $server = new FakeWebSocketServer();
        $chat = new ChatServer($server);
        $chat->configure();
        $chat->registerHandlers();

        $onMessage = $server->handlers['message'];
        $onClose = $server->handlers['close'];

        $onMessage(new FakeWebSocketFrame(31, json_encode([
            'action' => 'login',
            'user_id' => 'u-31',
            'group_id' => 'g-31',
        ])));
        $onClose($server, 31);
        $onMessage(new FakeWebSocketFrame(32, json_encode([
            'action' => 'login',
            'user_id' => 'u-32',
            'group_id' => 'g-31',
        ])));
        $onMessage(new FakeWebSocketFrame(32, json_encode([
            'action' => 'group_message',
            'content' => 'after close',
        ])));

        self::assertStringNotContainsString('"to_fd":31', implode("\n", $server->allPushBodies()));
    }
}

final class FakeWebSocketServer implements WebSocketServerInterface
{
    /** @var array<string, mixed> */
    public array $settings = [];

    /** @var array<string, callable> */
    public array $handlers = [];

    /** @var array<int, list<string>> */
    public array $pushed = [];

    /** @var array<int, bool> */
    public array $disconnected = [];

    public bool $started = false;

    public function set(array $settings): void
    {
        $this->settings = $settings;
    }

    public function on(string $event, callable $handler): void
    {
        $this->handlers[$event] = $handler;
    }

    public function start(): void
    {
        $this->started = true;
    }

    public function push(int $fd, string $data): bool
    {
        $this->pushed[$fd] ??= [];
        $this->pushed[$fd][] = $data;

        return true;
    }

    public function disconnect(int $fd, int $code = 1000, string $reason = ''): bool
    {
        $this->disconnected[$fd] = true;

        return true;
    }

    public function exists(int $fd): bool
    {
        return !($this->disconnected[$fd] ?? false);
    }

    public function latestPushBody(int $fd): ?string
    {
        $items = $this->pushed[$fd] ?? [];
        if ($items === []) {
            return null;
        }

        return $items[array_key_last($items)] ?? null;
    }

    /**
     * @return list<string>
     */
    public function allPushBodies(): array
    {
        $flatten = [];
        foreach ($this->pushed as $items) {
            foreach ($items as $body) {
                $flatten[] = $body;
            }
        }

        return $flatten;
    }
}

final class FakeWebSocketFrame
{
    public function __construct(
        public readonly int $fd,
        public readonly string $data
    ) {
    }
}
