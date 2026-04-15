<?php

declare(strict_types=1);

namespace SwooleLearn\ShortUrl\Exception;

use SwooleLearn\ShortUrl\Http\ErrorCode;

final class NotFoundException extends ShortUrlException
{
    public function __construct(string $message = 'Resource not found.')
    {
        parent::__construct($message, ErrorCode::SHORT_URL_NOT_FOUND);
    }
}
