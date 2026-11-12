<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Feature\Dashboard\Fixtures;

use Capell\Admin\Contracts\Dashboard\DashboardAnalyticsDataProvider;
use Capell\Admin\Data\Dashboard\DashboardAnalyticsSnapshotData;
use Illuminate\Contracts\Auth\Authenticatable;

/** An analytics source that has already counted visits. */
final class RecordedVisitsAnalyticsDataProvider implements DashboardAnalyticsDataProvider
{
    public function build(Authenticatable $actor, string $period, ?int $siteId = null, ?string $language = null): DashboardAnalyticsSnapshotData
    {
        return new DashboardAnalyticsSnapshotData(totalViews: 12, recentViews: 3, searches: 1, activePages: 2, collecting: true);
    }
}
