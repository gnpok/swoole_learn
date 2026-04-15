<?php

declare(strict_types=1);

namespace SwooleLearn\ShortUrl\Http;

use DateTimeImmutable;
use SwooleLearn\ShortUrl\Exception\ConflictException;
use SwooleLearn\ShortUrl\Exception\InactiveShortUrlException;
use SwooleLearn\ShortUrl\Exception\NotFoundException;
use SwooleLearn\ShortUrl\Exception\RateLimitException;
use SwooleLearn\ShortUrl\Exception\ValidationException;
use SwooleLearn\ShortUrl\Service\ShortUrlService;
use Throwable;

final class ShortUrlApiController
{
    public function __construct(private readonly ShortUrlService $service)
    {
    }

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed>|null $body
     */
    public function handle(
        string $method,
        string $path,
        array $query = [],
        ?array $body = null,
        string $clientIp = '0.0.0.0',
        string $userAgent = ''
    ): ApiResponse {
        try {
            if ($path === '/health') {
                return ApiResponse::json(200, ['status' => 'ok']);
            }

            if ($method === 'POST' && $path === '/api/v1/short-urls') {
                return $this->createShortUrl($body, $clientIp);
            }

            if ($method === 'GET' && preg_match('#^/r/([A-Za-z0-9_-]{4,16})$#', $path, $matches) === 1) {
                $record = $this->service->resolve($matches[1], $clientIp, $userAgent);

                return ApiResponse::redirect($record->originalUrl);
            }

            if ($method === 'GET' && preg_match('#^/api/v1/short-urls/([A-Za-z0-9_-]{4,16})$#', $path, $matches) === 1) {
                $detail = $this->service->getDetail($matches[1]);

                return ApiResponse::json(200, ['data' => $detail]);
            }

            if ($method === 'GET' && preg_match('#^/api/v1/short-urls/([A-Za-z0-9_-]{4,16})/stats$#', $path, $matches) === 1) {
                $detail = $this->service->getDetail($matches[1]);

                return ApiResponse::json(200, ['data' => $detail]);
            }

            if ($method === 'DELETE' && preg_match('#^/api/v1/short-urls/([A-Za-z0-9_-]{4,16})$#', $path, $matches) === 1) {
                $this->service->disable($matches[1]);

                return ApiResponse::noContent();
            }

            return ApiResponse::json(404, ['error' => 'Route not found.']);
        } catch (ValidationException $exception) {
            return ApiResponse::json(422, ['error' => $exception->getMessage()]);
        } catch (ConflictException $exception) {
            return ApiResponse::json(409, ['error' => $exception->getMessage()]);
        } catch (RateLimitException $exception) {
            return ApiResponse::json(429, ['error' => $exception->getMessage()]);
        } catch (NotFoundException $exception) {
            return ApiResponse::json(404, ['error' => $exception->getMessage()]);
        } catch (InactiveShortUrlException $exception) {
            return ApiResponse::json(410, ['error' => $exception->getMessage()]);
        } catch (Throwable $exception) {
            return ApiResponse::json(500, ['error' => 'Internal server error.', 'detail' => $exception->getMessage()]);
        }
    }

    /**
     * @param array<string, mixed>|null $body
     */
    private function createShortUrl(?array $body, string $clientIp): ApiResponse
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

        $record = $this->service->create(
            originalUrl: $url,
            customCode: $customCode,
            expiresAt: $expiresAt,
            clientIp: $clientIp
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
}
