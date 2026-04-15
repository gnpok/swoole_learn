<?php

declare(strict_types=1);

namespace SwooleLearn\ShortUrl\Http;

use SwooleLearn\Learning\Contracts\HttpServerInterface;

final class SwooleShortUrlServer
{
    public function __construct(
        private readonly HttpServerInterface $server,
        private readonly ShortUrlApiController $controller
    ) {
    }

    public function configure(array $settings = []): void
    {
        $defaults = [
            'worker_num' => 1,
            'daemonize' => false,
            'max_request' => 10000,
        ];

        $this->server->set(array_replace($defaults, $settings));
    }

    public function registerRoutes(): void
    {
        $this->server->on('request', function (object $request, object $response): void {
            $method = strtoupper((string) ($request->server['request_method'] ?? 'GET'));
            $path = (string) ($request->server['request_uri'] ?? '/');
            $query = property_exists($request, 'get') && is_array($request->get) ? $request->get : [];
            $clientIp = (string) ($request->server['remote_addr'] ?? '0.0.0.0');
            $headers = property_exists($request, 'header') && is_array($request->header)
                ? $request->header
                : [];
            $userAgent = (string) ($headers['user-agent'] ?? '');
            $body = $this->extractJsonBody($request, $method);

            $apiResponse = $this->controller->handle(
                new RequestContext(
                    method: $method,
                    path: $path,
                    query: $query,
                    body: $body,
                    headers: $this->normalizeHeaders($headers),
                    clientIp: $clientIp,
                    userAgent: $userAgent
                )
            );

            $response->status($apiResponse->statusCode);
            foreach ($apiResponse->headers as $key => $value) {
                $response->header($key, $value);
            }

            $response->end($apiResponse->body);
        });
    }

    public function start(): void
    {
        $this->server->start();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function extractJsonBody(object $request, string $method): ?array
    {
        if (!in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
            return null;
        }

        if (!method_exists($request, 'rawContent')) {
            return null;
        }

        $rawBody = (string) $request->rawContent();
        if ($rawBody === '') {
            return [];
        }

        $decoded = json_decode($rawBody, true);
        if (!is_array($decoded)) {
            return ['__invalid_json__' => true];
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed> $headers
     *
     * @return array<string, string>
     */
    private function normalizeHeaders(array $headers): array
    {
        $normalized = [];
        foreach ($headers as $key => $value) {
            $rawKey = strtolower((string) $key);
            $headerName = str_replace(' ', '-', ucwords(str_replace('-', ' ', $rawKey)));
            $normalized[$headerName] = (string) $value;
        }

        return $normalized;
    }
}
