<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Dashboard;

use Capell\Admin\Data\Dashboard\DashboardAnalyticsSnapshotData;
use Capell\Admin\Settings\AdminSettings;
use Capell\Admin\Support\SiteScope;
use Capell\Core\Enums\ActivityBucketSubjectEnum;
use Capell\Core\Models\ActivityBucket;
use Capell\Core\Models\MetricDailyRollup;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Site;
use Capell\Core\Support\Metrics\ActivityBucketsDailyMetricsCollector;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class BuildDashboardAnalyticsSnapshotAction
{
    public function handle(Authenticatable $actor, string $period, ?int $siteId = null, ?string $language = null): DashboardAnalyticsSnapshotData
    {
        $end = CarbonImmutable::now('UTC');
        $days = $this->periodDays($period);
        $start = $end->subDays($days);
        $todayStart = $end->startOfDay();
        $rawPageViews = $this->authorizedActivityQuery($actor, $siteId)
            ->whereBetween('bucket_started_at', [$start, $end])
            ->where('subject_type', ActivityBucketSubjectEnum::PageView->value)
            ->when($language !== null, fn (Builder $query): Builder => $query->where('language', $language));
        $rawCurrentDayViews = (int) (clone $rawPageViews)
            ->where('bucket_started_at', '>=', max($start, $todayStart))
            ->sum('count');
        $rollupViews = $days > 1
            ? $this->rollupSum($actor, ActivityBucketsDailyMetricsCollector::PAGE_VIEWS_METRIC, $start, $todayStart, $siteId, $language)
            : 0;
        $totalViews = $rollupViews > 0 ? $rawCurrentDayViews + $rollupViews : (int) (clone $rawPageViews)->sum('count');
        $recentViews = (int) (clone $rawPageViews)
            ->where('bucket_started_at', '>=', $end->subDay())
            ->sum('count');
        $activePages = (int) (clone $rawPageViews)->distinct('subject_key')->count('subject_key');

        $topPageRows = (clone $rawPageViews)
            ->select('subject_key')
            ->selectRaw('SUM(count) AS total_count')
            ->groupBy('subject_key')
            ->orderByDesc('total_count')
            ->limit($this->topLimit())
            ->toBase()
            ->get();
        $pageUrls = PageUrl::query()
            ->whereIn('id', $topPageRows->pluck('subject_key')->map(static fn (mixed $id): int => (int) $id))
            ->pluck('url', 'id');
        $topPages = array_values($topPageRows->map(static fn (object $row): array => [
            'url' => (string) ($pageUrls[(int) data_get($row, 'subject_key')] ?? '/'),
            'count' => (int) data_get($row, 'total_count'),
        ])->all());

        $trend = $this->trend($rawPageViews, $actor, $start, $todayStart, $days, $siteId, $language);
        $recentRows = (clone $rawPageViews)
            ->orderByDesc('bucket_started_at')
            ->limit(20)
            ->toBase()
            ->get(['bucket_started_at', 'subject_key', 'count']);
        $recentUrls = PageUrl::query()
            ->whereIn('id', $recentRows->pluck('subject_key')->map(static fn (mixed $id): int => (int) $id))
            ->pluck('url', 'id');
        $recentActivity = array_values($recentRows->map(static fn (object $row): array => [
            'label' => (string) ($recentUrls[(int) data_get($row, 'subject_key')] ?? '/'),
            'count' => (int) data_get($row, 'count'),
            'at' => (string) data_get($row, 'bucket_started_at'),
        ])->all());

        $searches = 0;
        $topSearchTerms = [];

        if (AdminSettings::instance()->analytics_search_collection_enabled) {
            $rawSearches = $this->authorizedActivityQuery($actor, $siteId)
                ->whereBetween('bucket_started_at', [$start, $end])
                ->where('subject_type', ActivityBucketSubjectEnum::SearchTerm->value)
                ->when($language !== null, fn (Builder $query): Builder => $query->where('language', $language));
            $rollupSearches = $days > 1
                ? $this->rollupSum($actor, ActivityBucketsDailyMetricsCollector::SEARCHES_METRIC, $start, $todayStart, $siteId, $language)
                : 0;
            $searches = $rollupSearches > 0
                ? $rollupSearches + (int) (clone $rawSearches)->where('bucket_started_at', '>=', max($start, $todayStart))->sum('count')
                : (int) (clone $rawSearches)->sum('count');
            $topSearchTerms = array_values((clone $rawSearches)
                ->select('subject_key')
                ->selectRaw('SUM(count) AS total_count')
                ->groupBy('subject_key')
                ->orderByDesc('total_count')
                ->limit($this->topLimit())
                ->toBase()
                ->get()
                ->map(static fn (object $row): array => [
                    'term' => (string) data_get($row, 'subject_key'),
                    'count' => (int) data_get($row, 'total_count'),
                ])
                ->all());
        }

        $freshThrough = (clone $rawPageViews)->max('bucket_started_at');

        return new DashboardAnalyticsSnapshotData(
            totalViews: $totalViews,
            recentViews: $recentViews,
            searches: $searches,
            activePages: $activePages,
            trend: $trend,
            topPages: $topPages,
            topSearchTerms: $topSearchTerms,
            recentActivity: $recentActivity,
            freshThrough: $freshThrough instanceof DateTimeInterface
                ? CarbonImmutable::instance($freshThrough)
                : (is_string($freshThrough) ? CarbonImmutable::parse($freshThrough, 'UTC') : null),
            collecting: (bool) AdminSettings::instance()->analytics_collection_enabled,
        );
    }

    /**
     * @param  Builder<ActivityBucket>  $rawQuery
     * @return list<array{bucket: string, views: int, searches: int}>
     */
    private function trend(Builder $rawQuery, Authenticatable $actor, CarbonImmutable $start, CarbonImmutable $todayStart, int $days, ?int $siteId, ?string $language): array
    {
        $expression = match (DB::connection()->getDriverName()) {
            'mysql', 'mariadb' => 'DATE(bucket_started_at)',
            'pgsql' => 'CAST(bucket_started_at AS DATE)',
            default => 'date(bucket_started_at)',
        };
        $raw = (clone $rawQuery)
            ->selectRaw($expression . ' AS bucket')
            ->selectRaw('SUM(count) AS total_count')
            ->groupByRaw($expression)
            ->orderBy('bucket')
            ->toBase()
            ->get();
        $byDay = [];

        foreach ($raw as $row) {
            $day = (string) data_get($row, 'bucket');
            $byDay[$day] = ['bucket' => $day, 'views' => (int) data_get($row, 'total_count'), 'searches' => 0];
        }

        if ($days > 1) {
            $rollups = $this->rollupQuery($actor, ActivityBucketsDailyMetricsCollector::PAGE_VIEWS_METRIC, $start, $todayStart, $siteId, $language)
                ->select('day')
                ->selectRaw('SUM(value) AS total_count')
                ->groupBy('day')
                ->orderBy('day')
                ->toBase()
                ->get();

            foreach ($rollups as $row) {
                $day = (string) data_get($row, 'day');
                $byDay[$day] = ['bucket' => $day, 'views' => (int) data_get($row, 'total_count'), 'searches' => 0];
            }
        }

        ksort($byDay);

        return array_values($byDay);
    }

    private function rollupSum(Authenticatable $actor, string $metric, CarbonImmutable $start, CarbonImmutable $todayStart, ?int $siteId, ?string $language): int
    {
        return (int) $this->rollupQuery($actor, $metric, $start, $todayStart, $siteId, $language)->sum('value');
    }

    /** @return Builder<MetricDailyRollup> */
    private function rollupQuery(Authenticatable $actor, string $metric, CarbonImmutable $start, CarbonImmutable $todayStart, ?int $siteId, ?string $language): Builder
    {
        $query = MetricDailyRollup::query()
            ->where('owner_package', ActivityBucketsDailyMetricsCollector::OWNER_PACKAGE)
            ->where('collector_key', ActivityBucketsDailyMetricsCollector::COLLECTOR_KEY)
            ->where('metric_key', $metric)
            ->whereDate('day', '>=', $start->toDateString())
            ->whereDate('day', '<', $todayStart->toDateString())
            ->when($language !== null, fn (Builder $query): Builder => $query->where('language', $language));

        if ($siteId !== null) {
            $site = Site::query()->find($siteId);

            return ! $site instanceof Site || ! SiteScope::actorCanUseSite($actor, $site)
                ? $query->whereRaw('1 = 0')
                : $query->where('site_id', $siteId);
        }

        return SiteScope::isGlobalActor($actor)
            ? $query
            : ($actor->getAssignedSiteIds()->isNotEmpty()
                ? $query->whereIn('site_id', $actor->getAssignedSiteIds())
                : $query->whereRaw('1 = 0'));
    }

    /** @return Builder<ActivityBucket> */
    private function authorizedActivityQuery(Authenticatable $actor, ?int $siteId): Builder
    {
        $query = ActivityBucket::query();

        if ($siteId !== null) {
            $site = Site::query()->find($siteId);

            return ! $site instanceof Site || ! SiteScope::actorCanUseSite($actor, $site)
                ? $query->whereRaw('1 = 0')
                : $query->where('site_id', $siteId);
        }

        return SiteScope::isGlobalActor($actor)
            ? $query
            : ($actor->getAssignedSiteIds()->isNotEmpty()
                ? $query->whereIn('site_id', $actor->getAssignedSiteIds())
                : $query->whereRaw('1 = 0'));
    }

    private function periodDays(string $period): int
    {
        return match ($period) {
            'today' => 1,
            'this_week' => 7,
            'this_month', 'last_30_days' => 30,
            'this_year' => 365,
            default => max(1, min(365, AdminSettings::instance()->analytics_default_period_days)),
        };
    }

    private function topLimit(): int
    {
        return max(1, min(100, AdminSettings::instance()->analytics_top_n_limit));
    }
}
