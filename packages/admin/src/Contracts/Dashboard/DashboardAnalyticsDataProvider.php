<?php

declare(strict_types=1);

namespace Capell\Admin\Contracts\Dashboard;

use Capell\Admin\Data\Dashboard\DashboardAnalyticsSnapshotData;
use Illuminate\Contracts\Auth\Authenticatable;

interface DashboardAnalyticsDataProvider
{
    public function build(Authenticatable $actor, string $period, ?int $siteId = null, ?string $language = null): DashboardAnalyticsSnapshotData;
}
