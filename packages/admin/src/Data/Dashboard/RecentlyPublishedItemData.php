<?php

declare(strict_types=1);

namespace Capell\Admin\Data\Dashboard;

use Spatie\LaravelData\Data;

final class RecentlyPublishedItemData extends Data
{
    public function __construct(
        public readonly int $pageId,
        public readonly string $title,
        public readonly string $siteName,
        public readonly ?string $publishedAt,
        public readonly ?string $editUrl,
    ) {}
}
