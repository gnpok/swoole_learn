<?php

declare(strict_types=1);

namespace SwooleLearn\ShortUrl\Entity;

final class ShortUrlPage
{
    /**
     * @param list<ShortUrlListItem> $items
     */
    public function __construct(
        public readonly array $items,
        public readonly int $page,
        public readonly int $perPage,
        public readonly int $total
    ) {
    }
}
