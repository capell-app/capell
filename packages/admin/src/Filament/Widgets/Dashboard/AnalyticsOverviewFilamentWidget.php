<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Widgets\Dashboard;

use Capell\Admin\Actions\Dashboard\ResolveDashboardPeriodWindowsAction;
use Capell\Admin\Contracts\CapellFilamentWidgetContract;
use Capell\Admin\Data\Dashboard\DashboardAnalyticsSnapshotData;
use Capell\Admin\Enums\DashboardAnalyticsAvailabilityEnum;
use Capell\Admin\Filament\Concerns\GatedByRoleAndSettings;
use Capell\Admin\Filament\Concerns\HasAnalyticsDashboardPeriod;
use Capell\Admin\Filament\Concerns\HasDashboardDateRange;
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

        $data = resolve(AdminDashboardDataRequestCache::class)->analyticsSnapshot(
            $actor,
            $this->getAnalyticsDashboardPeriod(),
            $this->getDashboardSiteId(),
            $this->getDashboardLanguage(),
        );

        if (! $data->isAvailable()) {
            return $this->unavailableStats($data);
        }

        $observed = $data->collecting
            ? __('capell-admin::dashboard.analytics_observed_description')
            : __('capell-admin::dashboard.analytics_collection_off_description');

        return [
            Stat::make(__('capell-admin::dashboard.analytics_views'), (string) $data->totalViews)
                ->description($observed)
                ->color('primary'),
            Stat::make(__('capell-admin::dashboard.analytics_recent_views'), (string) $data->recentViews)
                ->color('info'),
            Stat::make(__('capell-admin::dashboard.analytics_active_pages'), (string) $data->activePages)
                ->color('success'),
            Stat::make(__('capell-admin::dashboard.analytics_searches'), (string) $data->searches)
                ->color('gray'),
        ];
    }

    #[Override]
    protected function getDescription(): string
    {
        return ResolveDashboardPeriodWindowsAction::make()->describeRolling($this->getAnalyticsDashboardPeriod());
    }

    /**
     * Zero would read as "nothing happened", so show that no figure exists and why.
     *
     * @return list<Stat>
     */
    private function unavailableStats(DashboardAnalyticsSnapshotData $data): array
    {
        $reason = $data->availability === DashboardAnalyticsAvailabilityEnum::NotConfigured
            ? __('capell-admin::dashboard.analytics_not_configured_description')
            : __('capell-admin::dashboard.analytics_unavailable_description');
        $none = (string) __('capell-admin::dashboard.analytics_value_unavailable');

        return [
            Stat::make(__('capell-admin::dashboard.analytics_views'), $none)
                ->description($reason)
                ->color('warning'),
            Stat::make(__('capell-admin::dashboard.analytics_recent_views'), $none)->color('gray'),
            Stat::make(__('capell-admin::dashboard.analytics_active_pages'), $none)->color('gray'),
            Stat::make(__('capell-admin::dashboard.analytics_searches'), $none)->color('gray'),
        ];
    }
}
