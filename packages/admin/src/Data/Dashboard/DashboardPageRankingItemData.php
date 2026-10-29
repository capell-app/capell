<?php

declare(strict_types=1);

namespace Capell\Admin\Data\Dashboard;

use Spatie\LaravelData\Data;

final class DashboardPageRankingItemData extends Data
{
    public function __construct(
        public readonly int $pageId,
        public readonly int $siteId,
        public readonly string $siteName,
        public readonly string $title,
        public readonly int $current,
        public readonly ?int $previous,
        public readonly bool $isNew,
        public readonly ?string $publicUrl = null,
        public readonly ?string $editUrl = null,
        public readonly ?string $performanceUrl = null,
    ) {}
}
