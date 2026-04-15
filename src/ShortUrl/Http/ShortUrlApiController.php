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
use SwooleLearn\ShortUrl\Service\ShortUrlService;
use Throwable;

final class ShortUrlApiController
{
    public function __construct(
        private readonly ShortUrlService $service,
        private readonly ?string $adminApiKey = null
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

        try {
            if ($context->path === '/health') {
                return ApiResponse::json(200, ['status' => 'ok']);
            }

            if ($context->method === 'POST' && $context->path === '/api/v1/short-urls') {
                return $this->createShortUrl($body, $context);
            }

            if ($context->method === 'GET' && preg_match('#^/r/([A-Za-z0-9_-]{4,16})$#', $context->path, $matches) === 1) {
                $record = $this->service->resolve($matches[1], $context->clientIp, $context->userAgent);

                return ApiResponse::redirect($record->originalUrl);
            }

            if ($context->method === 'GET' && preg_match('#^/api/v1/short-urls/([A-Za-z0-9_-]{4,16})$#', $context->path, $matches) === 1) {
                $detail = $this->service->getDetail($matches[1]);

                return ApiResponse::json(200, ['data' => $detail]);
            }

            if ($context->method === 'GET' && preg_match('#^/api/v1/short-urls/([A-Za-z0-9_-]{4,16})/stats$#', $context->path, $matches) === 1) {
                $detail = $this->service->getDetail($matches[1]);

                return ApiResponse::json(200, ['data' => $detail]);
            }

            if ($context->method === 'DELETE' && preg_match('#^/api/v1/short-urls/([A-Za-z0-9_-]{4,16})$#', $context->path, $matches) === 1) {
                $this->service->disable($matches[1]);

                return ApiResponse::noContent();
            }

            if ($context->method === 'GET' && $context->path === '/api/v1/admin/short-urls') {
                return $this->listShortUrls($query, $context);
            }

            if ($context->method === 'POST' && $context->path === '/api/v1/admin/short-urls/bulk-disable') {
                return $this->bulkDisable($body, $context);
            }

            return ApiResponse::json(404, ['error' => 'Route not found.']);
        } catch (ValidationException $exception) {
            return ApiResponse::json(422, ['error' => $exception->getMessage()]);
        } catch (ConflictException $exception) {
            return ApiResponse::json(409, ['error' => $exception->getMessage()]);
        } catch (RateLimitException $exception) {
            return ApiResponse::json(429, ['error' => $exception->getMessage()]);
        } catch (UnauthorizedException $exception) {
            return ApiResponse::json(401, ['error' => $exception->getMessage()]);
        } catch (NotFoundException $exception) {
            return ApiResponse::json(404, ['error' => $exception->getMessage()]);
        } catch (InactiveShortUrlException $exception) {
            return ApiResponse::json(410, ['error' => $exception->getMessage()]);
        } catch (Throwable $exception) {
            return ApiResponse::json(500, ['error' => 'Internal server error.', 'detail' => $exception->getMessage()]);
        }
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
        $expected = $this->adminApiKey;
        if ($expected === null || trim($expected) === '') {
            return;
        }

        $provided = $context->headers['x-admin-api-key'] ?? '';
        if (!is_string($provided) || $provided === '' || !hash_equals($expected, $provided)) {
            throw new UnauthorizedException('Invalid admin API key.');
        }
    }
}
