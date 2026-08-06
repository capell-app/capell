<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Dashboard;

use Capell\Admin\Actions\Dashboard\BuildDashboardAnalyticsSnapshotAction;
use Capell\Admin\Contracts\Dashboard\DashboardAnalyticsDataProvider;
use Capell\Admin\Data\Dashboard\DashboardAnalyticsSnapshotData;
use Illuminate\Contracts\Auth\Authenticatable;

final class DefaultDashboardAnalyticsDataProvider implements DashboardAnalyticsDataProvider
{
    public function build(Authenticatable $actor, string $period, ?int $siteId = null, ?string $language = null): DashboardAnalyticsSnapshotData
    {
        return resolve(BuildDashboardAnalyticsSnapshotAction::class)->execute($actor, $period, $siteId, $language);
    }
}
