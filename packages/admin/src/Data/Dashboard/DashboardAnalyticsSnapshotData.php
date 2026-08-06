<?php

declare(strict_types=1);

namespace Capell\Admin\Data\Dashboard;

use Carbon\CarbonImmutable;
use Spatie\LaravelData\Data;

final class DashboardAnalyticsSnapshotData extends Data
{
    /**
     * @param  list<array{bucket: string, views: int, searches: int}>  $trend
     * @param  list<array{url: string, count: int}>  $topPages
     * @param  list<array{url: string, count: int, change: int}>  $trendingPages
     * @param  list<array{term: string, count: int}>  $topSearchTerms
     * @param  list<array{label: string, count: int, at: string}>  $recentActivity
     */
    public function __construct(
        public readonly int $totalViews,
        public readonly int $recentViews,
        public readonly int $searches,
        public readonly int $activePages,
        public readonly array $trend = [],
        public readonly array $topPages = [],
        public readonly array $trendingPages = [],
        public readonly array $topSearchTerms = [],
        public readonly array $recentActivity = [],
        public readonly ?CarbonImmutable $freshThrough = null,
        public readonly bool $collecting = false,
    ) {}
}
