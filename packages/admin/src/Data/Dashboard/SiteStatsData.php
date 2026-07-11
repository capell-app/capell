<?php

declare(strict_types=1);

namespace Capell\Admin\Data\Dashboard;

use Spatie\LaravelData\Data;

final class SiteStatsData extends Data
{
    public function __construct(
        public readonly int $workQueueCount,
        public readonly int $publishedCount,
        /** @var list<int> */
        public readonly array $sparklinePublished,
        public readonly int $pendingCount = 0,
        public readonly int $expiredCount = 0,
        public readonly int $totalPagesCount = 0,
    ) {}
}
