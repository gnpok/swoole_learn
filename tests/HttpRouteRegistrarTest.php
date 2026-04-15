<?php

declare(strict_types=1);

namespace SwooleLearn\Tests;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use SwooleLearn\Learning\Contracts\HttpServerInterface;
use SwooleLearn\Learning\HttpRouteRegistrar;

final class HttpRouteRegistrarTest extends TestCase
{
    public function test_it_registers_default_routes_and_handles_requests(): void
    {
        $fakeServer = new FakeHttpServer();
        $registrar = new HttpRouteRegistrar(
            $fakeServer,
            static fn (): DateTimeImmutable => new DateTimeImmutable('2026-04-14T00:00:00+00:00')
        );

        $registrar->configure(['worker_num' => 2]);
        $registrar->registerDefaultRoutes();

        self::assertSame(
            ['worker_num' => 2, 'daemonize' => false],
            $fakeServer->settings
        );
        self::assertArrayHasKey('request', $fakeServer->handlers);

        $requestHandler = $fakeServer->handlers['request'];

        $healthResponse = new FakeHttpResponse();
        $requestHandler((object) ['server' => ['request_uri' => '/health']], $healthResponse);
        self::assertSame(200, $healthResponse->statusCode);
        self::assertSame('{"status":"ok"}', $healthResponse->body);
        self::assertSame('application/json; charset=utf-8', $healthResponse->headers['Content-Type'] ?? null);

        $timeResponse = new FakeHttpResponse();
        $requestHandler((object) ['server' => ['request_uri' => '/time']], $timeResponse);
        self::assertSame(200, $timeResponse->statusCode);
        self::assertSame('{"now":"2026-04-14T00:00:00+00:00"}', $timeResponse->body);

        $notFoundResponse = new FakeHttpResponse();
        $requestHandler((object) ['server' => ['request_uri' => '/missing']], $notFoundResponse);
        self::assertSame(404, $notFoundResponse->statusCode);
        self::assertSame('Not Found', $notFoundResponse->body);
    }

    public function test_start_forwards_to_underlying_server(): void
    {
        $fakeServer = new FakeHttpServer();
        $registrar = new HttpRouteRegistrar($fakeServer);

        $registrar->start();

        self::assertTrue($fakeServer->started);
    }
}

final class FakeHttpServer implements HttpServerInterface
{
    /** @var array<string, mixed> */
    public array $settings = [];

    /** @var array<string, callable> */
    public array $handlers = [];

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
}

final class FakeHttpResponse
{
    /** @var array<string, string> */
    public array $headers = [];

    public ?int $statusCode = null;

    public string $body = '';

    public function header(string $name, string $value): void
    {
        $this->headers[$name] = $value;
    }

    public function status(int $statusCode): void
    {
        $this->statusCode = $statusCode;
    }

    public function end(string $body): void
    {
        $this->body = $body;
    }
}
