<?php

declare(strict_types=1);

namespace SwooleLearn\ShortUrl\Exception;

final class ConflictException extends ShortUrlException
{
    public function __construct(
        string $message = 'Resource conflict.',
        string $errorCode = \SwooleLearn\ShortUrl\Http\ErrorCode::RESOURCE_CONFLICT
    )
    {
        parent::__construct($message, $errorCode);
    }
}
