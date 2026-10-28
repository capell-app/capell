<?php

declare(strict_types=1);

namespace Capell\Admin\Data\Dashboard;

use Spatie\LaravelData\Data;

final class DashboardPageRankingsData extends Data
{
    /** @param list<DashboardPageRankingItemData> $items */
    public function __construct(
        public readonly string $status,
        public readonly string $measure,
        public readonly string $source,
        public readonly string $timezone,
        public readonly array $items,
        public readonly ?string $reportUrl,
        public readonly int $sitesRequested = 0,
        public readonly int $sitesCovered = 0,
        public readonly int $total = 0,
        public readonly ?string $lastUpdated = null,
    ) {}
}
