<?php

declare(strict_types=1);

namespace SwooleLearn\Tests\ShortUrl;

use PHPUnit\Framework\TestCase;

final class OpenApiSpecContractTest extends TestCase
{
    public function test_openapi_spec_contains_core_paths_and_security(): void
    {
        $spec = $this->loadSpec();

        self::assertSame('3.1.0', $spec['openapi'] ?? null);
        self::assertArrayHasKey('/api/v1/short-urls', $spec['paths'] ?? []);
        self::assertArrayHasKey('/api/v1/admin/short-urls', $spec['paths'] ?? []);
        self::assertArrayHasKey('/api/v1/admin/short-urls/bulk-disable', $spec['paths'] ?? []);

        $securitySchemes = $spec['components']['securitySchemes'] ?? [];
        self::assertArrayHasKey('AdminApiKey', $securitySchemes);
        self::assertSame('X-Admin-Api-Key', $securitySchemes['AdminApiKey']['name'] ?? null);
    }

    public function test_openapi_spec_defines_create_response_shape(): void
    {
        $spec = $this->loadSpec();
        $schema = $spec['components']['schemas']['CreateShortUrlResponse']['properties']['data']['properties'] ?? [];

        self::assertArrayHasKey('code', $schema);
        self::assertArrayHasKey('short_url', $schema);
        self::assertArrayHasKey('original_url', $schema);
        self::assertArrayHasKey('is_active', $schema);
        self::assertSame('boolean', $schema['is_active']['type'] ?? null);
    }

    public function test_openapi_spec_defines_admin_list_response_shape(): void
    {
        $spec = $this->loadSpec();
        $schema = $spec['components']['schemas']['AdminShortUrlListResponse']['properties']['data']['properties'] ?? [];

        self::assertArrayHasKey('items', $schema);
        self::assertArrayHasKey('page', $schema);
        self::assertArrayHasKey('per_page', $schema);
        self::assertArrayHasKey('total', $schema);
        self::assertSame('array', $schema['items']['type'] ?? null);
    }

    /**
     * @return array<string, mixed>
     */
    private function loadSpec(): array
    {
        $specPath = __DIR__ . '/../../docs/openapi-short-url.yaml';
        self::assertFileExists($specPath);

        if (!function_exists('yaml_parse_file')) {
            self::markTestSkipped('yaml_parse_file is not available in this runtime.');
        }

        $parsed = yaml_parse_file($specPath);
        self::assertIsArray($parsed);

        return $parsed;
    }
}
