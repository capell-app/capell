<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Dashboard;

use Capell\Admin\Data\Dashboard\DashboardAnalyticsSnapshotData;
use Capell\Admin\Settings\AdminSettings;
use Capell\Admin\Support\SiteScope;
use Capell\Core\Enums\ActivityBucketSubjectEnum;
use Capell\Core\Models\ActivityBucket;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Site;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;

final class BuildDashboardAnalyticsSnapshotAction
{
    public function execute(Authenticatable $actor, string $period, ?int $siteId = null, ?string $language = null): DashboardAnalyticsSnapshotData
    {
        $end = CarbonImmutable::now('UTC');
        $start = $end->subDay();
        $query = $this->authorizedQuery($actor, $siteId)
            ->whereBetween('bucket_started_at', [$start, $end])
            ->where('subject_type', ActivityBucketSubjectEnum::PageView->value)
            ->when($language !== null, fn (Builder $query): Builder => $query->where('language', $language));

        $recentStart = $end->subDay();
        $totalViews = (int) (clone $query)->sum('count');
        $recentViews = (int) (clone $query)->where('bucket_started_at', '>=', $recentStart)->sum('count');
        $activePages = (int) (clone $query)->distinct('subject_key')->count('subject_key');

        $topPageRows = (clone $query)
            ->select('subject_key')
            ->selectRaw('SUM(count) AS total_count')
            ->groupBy('subject_key')
            ->orderByDesc('total_count')
            ->limit($this->topLimit())
            ->get();
        $pageUrls = PageUrl::query()
            ->whereIn('id', $topPageRows->pluck('subject_key')->map(static fn (mixed $id): int => (int) $id))
            ->pluck('url', 'id');
        $topPages = $topPageRows
            ->map(static fn (object $row): array => [
                'url' => (string) ($pageUrls[(int) $row->subject_key] ?? '/'),
                'count' => (int) $row->total_count,
            ])
            ->values()
            ->all();

        $trendByDay = [];
        foreach ((clone $query)->get(['bucket_started_at', 'count']) as $row) {
            $bucket = $row->bucket_started_at instanceof DateTimeInterface
                ? CarbonImmutable::instance($row->bucket_started_at)
                : CarbonImmutable::parse((string) $row->bucket_started_at, 'UTC');
            $day = $bucket->toDateString();
            $trendByDay[$day] ??= ['bucket' => $day, 'views' => 0, 'searches' => 0];
            $trendByDay[$day]['views'] += (int) $row->count;
        }

        $recentRows = (clone $query)
            ->orderByDesc('bucket_started_at')
            ->limit(20)
            ->get(['bucket_started_at', 'subject_key', 'count']);
        $recentUrls = PageUrl::query()
            ->whereIn('id', $recentRows->pluck('subject_key')->map(static fn (mixed $id): int => (int) $id))
            ->pluck('url', 'id');
        $recentActivity = $recentRows->map(static fn (object $row): array => [
            'label' => (string) ($recentUrls[(int) $row->subject_key] ?? '/'),
            'count' => (int) $row->count,
            'at' => (string) $row->bucket_started_at,
        ])->values()->all();

        $freshThrough = (clone $query)->max('bucket_started_at');

        return new DashboardAnalyticsSnapshotData(
            totalViews: $totalViews,
            recentViews: $recentViews,
            searches: 0,
            activePages: $activePages,
            trend: array_values($trendByDay),
            topPages: $topPages,
            recentActivity: $recentActivity,
            freshThrough: $freshThrough instanceof DateTimeInterface
                ? CarbonImmutable::instance($freshThrough)
                : (is_string($freshThrough) ? CarbonImmutable::parse($freshThrough, 'UTC') : null),
            collecting: (bool) AdminSettings::instance()->analytics_collection_enabled,
        );
    }

    private function authorizedQuery(Authenticatable $actor, ?int $siteId): Builder
    {
        $query = ActivityBucket::query();

        if ($siteId !== null) {
            $site = Site::query()->find($siteId);

            if (! $site instanceof Site || ! SiteScope::actorCanUseSite($actor, $site)) {
                return $query->whereRaw('1 = 0');
            }

            return $query->where('site_id', $siteId);
        }

        if (SiteScope::isGlobalActor($actor)) {
            return $query;
        }

        return $actor->getAssignedSiteIds()->isNotEmpty()
            ? $query->whereIn('site_id', $actor->getAssignedSiteIds())
            : $query->whereRaw('1 = 0');
    }

    private function topLimit(): int
    {
        return max(1, min(100, AdminSettings::instance()->analytics_top_n_limit));
    }
}
