<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Widgets\Dashboard;

use Capell\Admin\Contracts\CapellFilamentWidgetContract;
use Capell\Admin\Data\Dashboard\DashboardAnalyticsSnapshotData;
use Capell\Admin\Filament\Concerns\GatedByRoleAndSettings;
use Capell\Admin\Filament\Concerns\HasAnalyticsDashboardPeriod;
use Capell\Admin\Filament\Concerns\HasDashboardDateRange;
use Capell\Admin\Support\Dashboard\AdminDashboardDataRequestCache;
use Filament\Widgets\Widget;
use Illuminate\Contracts\Auth\Authenticatable;

final class AnalyticsRecentActivityFilamentWidget extends Widget implements CapellFilamentWidgetContract
{
    use GatedByRoleAndSettings;
    use HasAnalyticsDashboardPeriod;
    use HasDashboardDateRange;

    /** @var list<string> */
    protected static array $rolesConfigKeys = ['admin', 'super_admin'];

    protected static string $settingsKey = 'analytics_recent_activity';

    protected string $view = 'capell-admin::widgets.dashboard.analytics-recent-activity';

    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = -70;

    public function data(): DashboardAnalyticsSnapshotData
    {
        $actor = auth()->user();

        if (! $actor instanceof Authenticatable) {
            return new DashboardAnalyticsSnapshotData(0, 0, 0, 0);
        }

        return resolve(AdminDashboardDataRequestCache::class)
            ->analyticsSnapshot($actor, $this->getAnalyticsDashboardPeriod());
    }
}
