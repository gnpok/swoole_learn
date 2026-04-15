<?php

declare(strict_types=1);

namespace SwooleLearn\ShortUrl\Exception;

use SwooleLearn\ShortUrl\Http\ErrorCode;
use RuntimeException;
use Throwable;

class ShortUrlException extends RuntimeException
{
    public function __construct(
        string $message = 'Short URL error.',
        private readonly string $errorCode = ErrorCode::INTERNAL_SERVER_ERROR,
        int $code = 0,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }
}
