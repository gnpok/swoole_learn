<?php

declare(strict_types=1);

namespace SwooleLearn\ShortUrl\Http;

final class ErrorCode
{
    public const ROUTE_NOT_FOUND = 'ROUTE_NOT_FOUND';
    public const VALIDATION_FAILED = 'VALIDATION_FAILED';
    public const SHORT_CODE_CONFLICT = 'SHORT_CODE_CONFLICT';
    public const RESOURCE_CONFLICT = 'RESOURCE_CONFLICT';
    public const RATE_LIMITED = 'RATE_LIMITED';
    public const UNAUTHORIZED = 'UNAUTHORIZED';
    public const SHORT_URL_NOT_FOUND = 'SHORT_URL_NOT_FOUND';
    public const SHORT_URL_INACTIVE = 'SHORT_URL_INACTIVE';
    public const INTERNAL_SERVER_ERROR = 'INTERNAL_SERVER_ERROR';
}
