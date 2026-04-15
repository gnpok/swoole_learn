<?php

declare(strict_types=1);

namespace SwooleLearn\Tests\ShortUrl;

use PHPUnit\Framework\TestCase;

final class OpenApiSpecContractTest extends TestCase
{
    public function test_openapi_spec_contains_core_paths_and_security(): void
    {
        $specPath = __DIR__ . '/../../docs/openapi-short-url.yaml';
        self::assertFileExists($specPath);

        $content = file_get_contents($specPath);
        self::assertIsString($content);
        self::assertStringContainsString('openapi: 3.1.0', $content);
        self::assertStringContainsString('/api/v1/short-urls:', $content);
        self::assertStringContainsString('/api/v1/admin/short-urls:', $content);
        self::assertStringContainsString('/api/v1/admin/short-urls/bulk-disable:', $content);
        self::assertStringContainsString('name: Idempotency-Key', $content);
        self::assertStringContainsString('name: X-Admin-Api-Key', $content);
        self::assertStringContainsString('AdminApiKey', $content);
    }
}
