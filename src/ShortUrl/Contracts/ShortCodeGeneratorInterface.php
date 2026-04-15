<?php

declare(strict_types=1);

namespace SwooleLearn\ShortUrl\Contracts;

interface ShortCodeGeneratorInterface
{
    public function generate(int $length = 7): string;
}
