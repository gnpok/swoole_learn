<?php

declare(strict_types=1);

namespace SwooleLearn\Learning;

use Closure;
use DateTimeImmutable;
use SwooleLearn\Learning\Contracts\HttpServerInterface;

final class HttpRouteRegistrar
{
    private Closure $clock;

    public function __construct(
        private readonly HttpServerInterface $server,
        ?callable $clock = null
    ) {
        $this->clock = $clock instanceof Closure
            ? $clock
            : Closure::fromCallable($clock ?? static fn (): DateTimeImmutable => new DateTimeImmutable());
    }

    public function configure(array $settings = []): void
    {
        $defaults = [
            'worker_num' => 1,
            'daemonize' => false,
        ];

        $this->server->set(array_replace($defaults, $settings));
    }

    public function registerDefaultRoutes(): void
    {
        $this->server->on('request', function (object $request, object $response): void {
            $path = $this->resolvePath($request);

            if ($path === '/health') {
                $response->header('Content-Type', 'application/json; charset=utf-8');
                $response->status(200);
                $response->end('{"status":"ok"}');

                return;
            }

            if ($path === '/time') {
                $response->header('Content-Type', 'application/json; charset=utf-8');
                $response->status(200);
                $response->end(sprintf('{"now":"%s"}', ($this->clock)()->format(DATE_ATOM)));

                return;
            }

            $response->status(404);
            $response->end('Not Found');
        });
    }

    public function start(): void
    {
        $this->server->start();
    }

    private function resolvePath(object $request): string
    {
        if (!property_exists($request, 'server') || !is_array($request->server)) {
            return '/';
        }

        $path = $request->server['request_uri'] ?? '/';

        return is_string($path) && $path !== '' ? $path : '/';
    }
}
