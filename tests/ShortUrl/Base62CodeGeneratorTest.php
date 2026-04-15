<?php

declare(strict_types=1);

namespace SwooleLearn\Tests\ShortUrl;

use PHPUnit\Framework\TestCase;
use SwooleLearn\ShortUrl\Support\Base62CodeGenerator;

final class Base62CodeGeneratorTest extends TestCase
{
    public function test_it_generates_base62_code_with_requested_length(): void
    {
        $generator = new Base62CodeGenerator();
        $code = $generator->generate(10);

        self::assertSame(10, strlen($code));
        self::assertSame(1, preg_match('/^[0-9a-zA-Z]+$/', $code));
    }
}
