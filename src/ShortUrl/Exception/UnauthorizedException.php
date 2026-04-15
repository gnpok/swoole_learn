<?php

declare(strict_types=1);

namespace SwooleLearn\ShortUrl\Exception;

final class UnauthorizedException extends ShortUrlException
{
    public function __construct(string $message = 'Invalid admin API key.')
    {
        parent::__construct($message, \SwooleLearn\ShortUrl\Http\ErrorCode::UNAUTHORIZED);
    }
}
