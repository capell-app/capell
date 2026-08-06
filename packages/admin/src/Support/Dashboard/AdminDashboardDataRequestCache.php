<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Dashboard;

use Capell\Admin\Contracts\Dashboard\DashboardAnalyticsDataProvider;
use Capell\Admin\Contracts\Dashboard\MyWorkQueueDataProvider;
use Capell\Admin\Contracts\Dashboard\RecentlyPublishedDataProvider;
use Capell\Admin\Contracts\Dashboard\SiteStatsDataProvider;
use Capell\Admin\Data\Dashboard\DashboardAnalyticsSnapshotData;
use Capell\Admin\Data\Dashboard\MyWorkQueueData;
use Capell\Admin\Data\Dashboard\RecentlyPublishedData;
use Capell\Admin\Data\Dashboard\SiteStatsData;
use Illuminate\Contracts\Auth\Authenticatable;

final class AdminDashboardDataRequestCache
{
    /** @var array<string, MyWorkQueueData> */
    private array $myWorkQueue = [];

    /** @var array<int, RecentlyPublishedData> */
    private array $recentlyPublished = [];

    /** @var array<string, SiteStatsData> */
    private array $siteStats = [];

    /** @var array<string, DashboardAnalyticsSnapshotData> */
    private array $analytics = [];

    public function myWorkQueue(Authenticatable $user, int $limit): MyWorkQueueData
    {
        $key = sprintf('%s:%s', $user->getAuthIdentifier(), $limit);

        return $this->myWorkQueue[$key] ??= resolve(MyWorkQueueDataProvider::class)->build($user, $limit);
    }

    public function recentlyPublished(int $limit): RecentlyPublishedData
    {
        return $this->recentlyPublished[$limit] ??= resolve(RecentlyPublishedDataProvider::class)->build($limit);
    }

    public function siteStats(string $period): SiteStatsData
    {
        return $this->siteStats[$period] ??= resolve(SiteStatsDataProvider::class)->build($period);
    }

    public function analyticsSnapshot(Authenticatable $user, string $period, ?int $siteId = null, ?string $language = null): DashboardAnalyticsSnapshotData
    {
        $key = implode(':', [$user->getAuthIdentifier(), $period, $siteId ?? 'all', $language ?? 'all']);

        return $this->analytics[$key] ??= resolve(DashboardAnalyticsDataProvider::class)->build($user, $period, $siteId, $language);
    }
}
