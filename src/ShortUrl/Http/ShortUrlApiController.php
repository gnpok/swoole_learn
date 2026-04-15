<?php

declare(strict_types=1);

namespace SwooleLearn\ShortUrl\Http;

use DateTimeImmutable;
use SwooleLearn\ShortUrl\Exception\ConflictException;
use SwooleLearn\ShortUrl\Exception\InactiveShortUrlException;
use SwooleLearn\ShortUrl\Exception\NotFoundException;
use SwooleLearn\ShortUrl\Exception\RateLimitException;
use SwooleLearn\ShortUrl\Exception\UnauthorizedException;
use SwooleLearn\ShortUrl\Exception\ValidationException;
use SwooleLearn\ShortUrl\Observability\HealthReporterInterface;
use SwooleLearn\ShortUrl\Observability\LoggerInterface;
use SwooleLearn\ShortUrl\Observability\MetricsCollectorInterface;
use SwooleLearn\ShortUrl\Observability\NullLogger;
use SwooleLearn\ShortUrl\Observability\NullMetricsCollector;
use SwooleLearn\ShortUrl\Service\ShortUrlService;
use Throwable;

final class ShortUrlApiController
{
    private int $inFlightRequests = 0;

    public function __construct(
        private readonly ShortUrlService $service,
        private readonly ?string $adminApiKey = null,
        private readonly ?MetricsCollectorInterface $metrics = null,
        private readonly ?LoggerInterface $logger = null,
        private readonly ?HealthReporterInterface $healthReporter = null
    )
    {
    }

    /**
     * @param array<string, mixed>|null $query
     * @param array<string, mixed>|null $body
     */
    public function handle(RequestContext $context): ApiResponse
    {
        $query = $context->query ?? [];
        $body = $context->body;
        $traceId = $context->traceId !== '' ? $context->traceId : $this->newTraceId();
        $route = 'unknown';
        $method = strtoupper($context->method);
        $response = null;
        $startedAtNs = hrtime(true);
        $metrics = $this->metrics ?? new NullMetricsCollector();
        $logger = $this->logger ?? new NullLogger();

        $this->inFlightRequests++;
        $metrics->setGauge(
            name: 'shorturl_http_in_flight_requests',
            value: (float) $this->inFlightRequests,
            help: 'Number of currently processing HTTP requests.'
        );

        try {
            if ($context->path === '/health') {
                $route = 'health';
                $response = ApiResponse::json(200, [
                    'status' => 'ok',
                    'service' => 'short-url-api',
                    'timestamp' => (new DateTimeImmutable())->format(DATE_ATOM),
                ]);
            } elseif ($context->method === 'GET' && $context->path === '/readyz') {
                $route = 'readyz';
                $report = $this->healthReporter?->report() ?? [
                    'status' => 'up',
                    'timestamp' => (new DateTimeImmutable())->format(DATE_ATOM),
                    'checks' => [],
                ];
                $statusCode = ($report['status'] ?? 'down') === 'down' ? 503 : 200;
                $response = ApiResponse::json($statusCode, $report);
            } elseif ($context->method === 'GET' && $context->path === '/metrics') {
                $route = 'metrics';
                $response = ApiResponse::text(
                    200,
                    $metrics->renderPrometheus(),
                    ['Content-Type' => 'text/plain; version=0.0.4; charset=utf-8']
                );
            } elseif ($context->method === 'POST' && $context->path === '/api/v1/short-urls') {
                $route = 'create_short_url';
                $response = $this->createShortUrl($body, $context);
            } elseif ($context->method === 'GET' && preg_match('#^/r/([A-Za-z0-9_-]{4,16})$#', $context->path, $matches) === 1) {
                $route = 'redirect_short_url';
                $record = $this->service->resolve($matches[1], $context->clientIp, $context->userAgent);
                $response = ApiResponse::redirect($record->originalUrl);
            } elseif ($context->method === 'GET' && preg_match('#^/api/v1/short-urls/([A-Za-z0-9_-]{4,16})$#', $context->path, $matches) === 1) {
                $route = 'detail_short_url';
                $detail = $this->service->getDetail($matches[1]);
                $response = ApiResponse::json(200, ['data' => $detail]);
            } elseif ($context->method === 'GET' && preg_match('#^/api/v1/short-urls/([A-Za-z0-9_-]{4,16})/stats$#', $context->path, $matches) === 1) {
                $route = 'stats_short_url';
                $detail = $this->service->getDetail($matches[1]);
                $response = ApiResponse::json(200, ['data' => $detail]);
            } elseif ($context->method === 'DELETE' && preg_match('#^/api/v1/short-urls/([A-Za-z0-9_-]{4,16})$#', $context->path, $matches) === 1) {
                $route = 'disable_short_url';
                $this->service->disable($matches[1]);
                $response = ApiResponse::noContent();
            } elseif ($context->method === 'GET' && $context->path === '/api/v1/admin/short-urls') {
                $route = 'admin_list_short_urls';
                $response = $this->listShortUrls($query, $context);
            } elseif ($context->method === 'POST' && $context->path === '/api/v1/admin/short-urls/bulk-disable') {
                $route = 'admin_bulk_disable';
                $response = $this->bulkDisable($body, $context);
            } else {
                $response = ApiResponse::json(404, ['error' => 'Route not found.']);
            }
        } catch (ValidationException $exception) {
            $response = ApiResponse::json(422, ['error' => $exception->getMessage()]);
        } catch (ConflictException $exception) {
            $response = ApiResponse::json(409, ['error' => $exception->getMessage()]);
        } catch (RateLimitException $exception) {
            $response = ApiResponse::json(429, ['error' => $exception->getMessage()]);
        } catch (UnauthorizedException $exception) {
            $response = ApiResponse::json(401, ['error' => $exception->getMessage()]);
        } catch (NotFoundException $exception) {
            $response = ApiResponse::json(404, ['error' => $exception->getMessage()]);
        } catch (InactiveShortUrlException $exception) {
            $response = ApiResponse::json(410, ['error' => $exception->getMessage()]);
        } catch (Throwable $exception) {
            $response = ApiResponse::json(500, ['error' => 'Internal server error.', 'detail' => $exception->getMessage()]);
            $logger->error('Unhandled API error', [
                'trace_id' => $traceId,
                'method' => $method,
                'path' => $context->path,
                'error' => $exception->getMessage(),
            ]);
        } finally {
            $durationSeconds = (hrtime(true) - $startedAtNs) / 1_000_000_000;
            $statusCode = $response?->statusCode ?? 500;
            $metrics->incrementCounter(
                name: 'shorturl_http_requests_total',
                labels: [
                    'method' => $method,
                    'route' => $route,
                    'status_code' => (string) $statusCode,
                ],
                help: 'Total HTTP requests processed by short URL API.'
            );
            $metrics->observeHistogram(
                name: 'shorturl_http_request_duration_seconds',
                value: $durationSeconds,
                labels: [
                    'method' => $method,
                    'route' => $route,
                ],
                help: 'Latency histogram for HTTP requests.'
            );
            $this->inFlightRequests = max(0, $this->inFlightRequests - 1);
            $metrics->setGauge(
                name: 'shorturl_http_in_flight_requests',
                value: (float) $this->inFlightRequests,
                help: 'Number of currently processing HTTP requests.'
            );
            $logContext = [
                'trace_id' => $traceId,
                'method' => $method,
                'path' => $context->path,
                'route' => $route,
                'status_code' => $statusCode,
                'duration_ms' => round($durationSeconds * 1000, 3),
                'client_ip' => $context->clientIp,
            ];
            if ($statusCode >= 500) {
                $logger->error('HTTP request failed', $logContext);
            } elseif ($statusCode >= 400) {
                $logger->warning('HTTP request rejected', $logContext);
            } else {
                $logger->info('HTTP request handled', $logContext);
            }
        }

        return $this->withTrace($response ?? ApiResponse::json(500, ['error' => 'Internal server error.']), $traceId);
    }

    /**
     * Backward-compatible entry for existing tests/callers.
     *
     * @param array<string, mixed> $query
     * @param array<string, mixed>|null $body
     */
    public function handleLegacy(
        string $method,
        string $path,
        array $query = [],
        ?array $body = null,
        string $clientIp = '0.0.0.0',
        string $userAgent = '',
        array $headers = []
    ): ApiResponse {
        return $this->handle(new RequestContext($method, $path, $query, $body, $clientIp, $userAgent, $headers));
    }

    /**
     * @param array<string, mixed>|null $body
     */
    private function createShortUrl(?array $body, RequestContext $context): ApiResponse
    {
        if ($body === null || ($body['__invalid_json__'] ?? false) === true) {
            throw new ValidationException('Request body must be valid JSON.');
        }

        $url = isset($body['url']) ? (string) $body['url'] : '';
        $customCode = isset($body['custom_code']) ? (string) $body['custom_code'] : null;
        $expiresAt = null;
        if (isset($body['expires_at']) && $body['expires_at'] !== null && $body['expires_at'] !== '') {
            try {
                $expiresAt = new DateTimeImmutable((string) $body['expires_at']);
            } catch (Throwable) {
                throw new ValidationException('expires_at must be a valid datetime string.');
            }
        }

        $idempotencyKey = $context->headers['idempotency-key'] ?? $context->headers['Idempotency-Key'] ?? null;
        $record = is_string($idempotencyKey) && trim($idempotencyKey) !== ''
            ? $this->service->createIdempotent(
                idempotencyKey: $idempotencyKey,
                originalUrl: $url,
                customCode: $customCode,
                expiresAt: $expiresAt,
                clientIp: $context->clientIp
            )
            : $this->service->create(
                originalUrl: $url,
                customCode: $customCode,
                expiresAt: $expiresAt,
                clientIp: $context->clientIp
            );

        return ApiResponse::json(201, [
            'data' => [
                'code' => $record->code,
                'short_url' => $this->service->buildShortUrl($record->code),
                'original_url' => $record->originalUrl,
                'created_at' => $record->createdAt->format(DATE_ATOM),
                'expires_at' => $record->expiresAt?->format(DATE_ATOM),
                'is_active' => $record->isActive,
            ],
        ]);
    }

    /**
     * @param array<string, mixed> $query
     */
    private function listShortUrls(array $query, RequestContext $context): ApiResponse
    {
        $this->assertAdminAuthorized($context);
        $page = isset($query['page']) ? (int) $query['page'] : 1;
        $perPage = isset($query['per_page']) ? (int) $query['per_page'] : 20;
        $keyword = isset($query['keyword']) ? (string) $query['keyword'] : null;

        $isActive = null;
        if (isset($query['is_active'])) {
            $raw = strtolower((string) $query['is_active']);
            if (in_array($raw, ['1', 'true', 'yes'], true)) {
                $isActive = true;
            } elseif (in_array($raw, ['0', 'false', 'no'], true)) {
                $isActive = false;
            } else {
                throw new ValidationException('is_active must be boolean-like value.');
            }
        }

        return ApiResponse::json(200, [
            'data' => $this->service->listShortUrls($page, $perPage, $isActive, $keyword),
        ]);
    }

    /**
     * @param array<string, mixed>|null $body
     */
    private function bulkDisable(?array $body, RequestContext $context): ApiResponse
    {
        $this->assertAdminAuthorized($context);
        if ($body === null || !isset($body['codes']) || !is_array($body['codes'])) {
            throw new ValidationException('codes must be an array.');
        }

        /** @var list<string> $codes */
        $codes = array_values(array_map(static fn (mixed $code): string => (string) $code, $body['codes']));
        $result = $this->service->bulkDisable($codes);

        return ApiResponse::json(200, ['data' => $result]);
    }

    private function assertAdminAuthorized(RequestContext $context): void
    {
        $allowedKeys = $this->resolveAllowedAdminKeys();
        if ($allowedKeys === []) {
            return;
        }

        $provided = $context->headers['x-admin-api-key'] ?? null;
        if (!is_string($provided) || $provided === '') {
            throw new UnauthorizedException('Invalid admin API key.');
        }

        foreach ($allowedKeys as $allowedKey) {
            if (hash_equals($allowedKey, $provided)) {
                return;
            }
        }

        throw new UnauthorizedException('Invalid admin API key.');
    }

    /**
     * @return list<string>
     */
    private function resolveAllowedAdminKeys(): array
    {
        $envKeysRaw = getenv('ADMIN_API_KEYS');
        if (is_string($envKeysRaw) && trim($envKeysRaw) !== '') {
            $keys = array_values(array_filter(array_map(
                static fn (string $key): string => trim($key),
                explode(',', $envKeysRaw)
            ), static fn (string $key): bool => $key !== ''));

            if ($keys !== []) {
                return $keys;
            }
        }

        if ($this->adminApiKey === null || trim($this->adminApiKey) === '') {
            return [];
        }

        return [trim($this->adminApiKey)];
    }

    private function withTrace(ApiResponse $response, string $traceId): ApiResponse
    {
        $headers = $response->headers;
        if (!isset($headers['X-Trace-Id']) && !isset($headers['x-trace-id'])) {
            $headers['X-Trace-Id'] = $traceId;
        }

        return new ApiResponse($response->statusCode, $response->body, $headers);
    }

    private function newTraceId(): string
    {
        try {
            return bin2hex(random_bytes(8));
        } catch (Throwable) {
            return str_replace('.', '', uniqid('trace', true));
        }
    }
}
