<?php

declare(strict_types=1);

namespace SwooleLearn\ShortUrl\Exception;

final class ValidationException extends ShortUrlException
{
    public function __construct(string $message = 'Validation failed.')
    {
        parent::__construct($message, \SwooleLearn\ShortUrl\Http\ErrorCode::VALIDATION_FAILED);
    }
}
