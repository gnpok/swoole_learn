<?php

declare(strict_types=1);

namespace SwooleLearn\ShortUrl\Support;

use SwooleLearn\ShortUrl\Contracts\ShortCodeGeneratorInterface;

final class Base62CodeGenerator implements ShortCodeGeneratorInterface
{
    private const ALPHABET = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';

    public function generate(int $length = 7): string
    {
        if ($length <= 0) {
            $length = 7;
        }

        $maxIndex = strlen(self::ALPHABET) - 1;
        $result = '';

        for ($i = 0; $i < $length; $i++) {
            $result .= self::ALPHABET[random_int(0, $maxIndex)];
        }

        return $result;
    }
}
