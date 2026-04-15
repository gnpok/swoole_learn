<?php

declare(strict_types=1);

namespace SwooleLearn\ShortUrl\Exception;

final class RateLimitException extends ShortUrlException
{
    public function __construct(string $message = 'Too many requests.')
    {
        parent::__construct($message, \SwooleLearn\ShortUrl\Http\ErrorCode::RATE_LIMITED);
    }
}
