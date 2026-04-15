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
            $rawHeaders = property_exists($request, 'header') && is_array($request->header)
                ? $request->header
                : [];
            $headers = $this->normalizeHeaders($rawHeaders);
            $userAgent = (string) ($headers['user-agent'] ?? '');
            $body = $this->extractJsonBody($request, $method);
            $traceId = $headers['x-trace-id']
                ?? TraceContext::extractTraceIdFromTraceparent($headers['traceparent'] ?? '')
                ?? $this->newTraceId();

            $apiResponse = $this->controller->handle(
                new RequestContext(
                    method: $method,
                    path: $path,
                    query: $query,
                    body: $body,
                    headers: $headers,
                    clientIp: $clientIp,
                    userAgent: $userAgent,
                    traceId: $traceId
                )
            );

            $response->status($apiResponse->statusCode);
            $headersToSend = $apiResponse->headers;
            if (!isset($headersToSend['X-Trace-Id']) && !isset($headersToSend['x-trace-id'])) {
                $headersToSend['X-Trace-Id'] = $traceId;
            }
            foreach ($headersToSend as $key => $value) {
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
            $normalized[strtolower((string) $key)] = (string) $value;
        }

        return $normalized;
    }

    private function newTraceId(): string
    {
        try {
            return bin2hex(random_bytes(8));
        } catch (\Throwable) {
            return str_replace('.', '', uniqid('trace', true));
        }
    }
}
