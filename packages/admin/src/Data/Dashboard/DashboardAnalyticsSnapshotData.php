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
        public readonly int $visitors = 0,
        public readonly int $previousVisitors = 0,
        public readonly int $previousViews = 0,
        public readonly bool $hasPreviousPeriod = false,
        public readonly array $trend = [],
        public readonly array $topPages = [],
        public readonly array $trendingPages = [],
        public readonly array $topSearchTerms = [],
        public readonly array $recentActivity = [],
        public readonly ?CarbonImmutable $freshThrough = null,
        public readonly bool $collecting = false,
    ) {}

    /**
     * Visitors counted once per site, per day. The visitor hash is salted with
     * the UTC day, so the same person on Monday and Tuesday is deliberately
     * unlinkable and counts twice over a multi-day window. That is the cost of
     * making cross-day re-identification impossible, and it is the same
     * definition used on both sides of every period comparison.
     */
    public function viewsPerVisitor(): ?float
    {
        return $this->visitors > 0
            ? round($this->totalViews / $this->visitors, 1)
            : null;
    }

    /**
     * Null rather than zero when there is nothing to compare against: a fresh
     * install has no previous period, and rendering "-100%" there is a lie.
     */
    public function visitorsChangePercent(): ?float
    {
        return $this->changePercent($this->visitors, $this->previousVisitors);
    }

    public function viewsChangePercent(): ?float
    {
        return $this->changePercent($this->totalViews, $this->previousViews);
    }

    private function changePercent(int $current, int $previous): ?float
    {
        return $this->hasPreviousPeriod && $previous > 0
            ? round((($current - $previous) / $previous) * 100, 1)
            : null;
    }
}
