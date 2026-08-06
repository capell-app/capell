<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Widgets\Dashboard;

use Capell\Admin\Contracts\CapellFilamentWidgetContract;
use Capell\Admin\Filament\Concerns\GatedByRoleAndSettings;
use Capell\Admin\Filament\Concerns\HasAnalyticsDashboardPeriod;
use Capell\Admin\Filament\Concerns\HasDashboardDateRange;
use Capell\Admin\Settings\AdminSettings;
use Capell\Admin\Support\Dashboard\AdminDashboardDataRequestCache;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Contracts\Auth\Authenticatable;
use Override;

final class AnalyticsOverviewFilamentWidget extends StatsOverviewWidget implements CapellFilamentWidgetContract
{
    use GatedByRoleAndSettings;
    use HasAnalyticsDashboardPeriod;
    use HasDashboardDateRange;

    /** @var list<string> */
    protected static array $rolesConfigKeys = ['admin', 'super_admin'];

    protected static string $settingsKey = 'analytics_overview';

    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = -100;

    #[Override]
    protected function getStats(): array
    {
        $actor = auth()->user();

        if (! $actor instanceof Authenticatable) {
            return [];
        }

        $data = resolve(AdminDashboardDataRequestCache::class)->analyticsSnapshot($actor, $this->getAnalyticsDashboardPeriod());

        return [
            Stat::make(__('capell-admin::dashboard.analytics_views'), (string) $data->totalViews)
                ->description(__('capell-admin::dashboard.analytics_observed_description'))
                ->color('primary'),
            Stat::make(__('capell-admin::dashboard.analytics_recent_views'), (string) $data->recentViews)
                ->color('info'),
            Stat::make(__('capell-admin::dashboard.analytics_active_pages'), (string) $data->activePages)
                ->color('success'),
            Stat::make(__('capell-admin::dashboard.analytics_searches'), (string) $data->searches)
                ->color('gray'),
        ];
    }

    protected function getPollingInterval(): ?string
    {
        $seconds = max(0, min(3600, AdminSettings::instance()->analytics_refresh_interval_seconds));

        return $seconds > 0 ? $seconds . 's' : null;
    }
}
