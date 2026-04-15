<?php

declare(strict_types=1);

namespace SwooleLearn\ShortUrl\Exception;

use SwooleLearn\ShortUrl\Http\ErrorCode;

final class ConflictShortCodeException extends ConflictException
{
    public function __construct(string $message = 'Short code already exists.')
    {
        parent::__construct($message, ErrorCode::SHORT_CODE_CONFLICT);
    }
}
