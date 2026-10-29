<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Dashboard;

use Capell\Admin\Actions\Dashboard\BuildDashboardAnalyticsSnapshotAction;
use Capell\Admin\Contracts\Dashboard\DashboardAnalyticsDataProvider;
use Capell\Admin\Data\Dashboard\DashboardAnalyticsSnapshotData;
use Capell\Admin\Enums\DashboardAnalyticsAvailabilityEnum;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Log;
use Throwable;

final class DefaultDashboardAnalyticsDataProvider implements DashboardAnalyticsDataProvider
{
    public function build(Authenticatable $actor, string $period, ?int $siteId = null, ?string $language = null): DashboardAnalyticsSnapshotData
    {
        try {
            return resolve(BuildDashboardAnalyticsSnapshotAction::class)->handle($actor, $period, $siteId, $language);
        } catch (Throwable $throwable) {
            Log::warning('Capell dashboard analytics snapshot failed.', [
                'exception' => $throwable::class,
            ]);

            // Zero values here would read as "no activity", so say the figures are unavailable.
            return new DashboardAnalyticsSnapshotData(
                totalViews: 0,
                recentViews: 0,
                searches: 0,
                activePages: 0,
                availability: DashboardAnalyticsAvailabilityEnum::Unavailable,
            );
        }
    }
}
