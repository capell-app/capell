<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Dashboard;

use Capell\Admin\Contracts\Dashboard\DashboardAnalyticsDataProvider;
use Capell\Admin\Data\Dashboard\DashboardAnalyticsSnapshotData;
use Illuminate\Contracts\Auth\Authenticatable;

final class NullDashboardAnalyticsDataProvider implements DashboardAnalyticsDataProvider
{
    public function build(Authenticatable $actor, string $period, ?int $siteId = null, ?string $language = null): DashboardAnalyticsSnapshotData
    {
        return new DashboardAnalyticsSnapshotData(
            totalViews: 0,
            recentViews: 0,
            searches: 0,
            activePages: 0,
            collecting: false,
        );
    }
}
