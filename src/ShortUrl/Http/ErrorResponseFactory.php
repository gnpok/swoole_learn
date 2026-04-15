<?php

declare(strict_types=1);

namespace SwooleLearn\ShortUrl\Http;

use SwooleLearn\ShortUrl\Exception\ShortUrlException;
use Throwable;

final class ErrorResponseFactory
{
    /**
     * @param array<string, mixed> $details
     */
    public function build(
        int $statusCode,
        string $code,
        string $message,
        string $traceId,
        array $details = []
    ): ApiResponse {
        $payload = [
            'error' => [
                'code' => $code,
                'message' => $message,
                'trace_id' => $traceId,
            ],
        ];

        if ($details !== []) {
            $payload['error']['details'] = $details;
        }

        return ApiResponse::json($statusCode, $payload);
    }

    public function fromException(ShortUrlException $exception, int $statusCode, string $traceId): ApiResponse
    {
        return $this->build(
            statusCode: $statusCode,
            code: $exception->errorCode(),
            message: $exception->getMessage(),
            traceId: $traceId
        );
    }

    public function fromThrowable(Throwable $throwable, string $traceId): ApiResponse
    {
        return $this->build(
            statusCode: 500,
            code: ErrorCode::INTERNAL_SERVER_ERROR,
            message: 'Internal server error.',
            traceId: $traceId,
            details: ['exception' => $throwable::class]
        );
    }
}
