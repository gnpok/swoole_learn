<?php

declare(strict_types=1);

namespace SwooleLearn\ShortUrl\Exception;

final class InactiveShortUrlException extends ShortUrlException
{
    public function __construct(string $message = 'Short URL has been disabled or expired.')
    {
        parent::__construct($message, \SwooleLearn\ShortUrl\Http\ErrorCode::SHORT_URL_INACTIVE);
    }
}
