<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Widgets\Dashboard;

use Capell\Admin\Actions\Dashboard\ResolveDashboardPeriodWindowsAction;
use Capell\Admin\Contracts\CapellFilamentWidgetContract;
use Capell\Admin\Data\Dashboard\SiteStatsData;
use Capell\Admin\Filament\Concerns\GatedByRoleAndSettings;
use Capell\Admin\Filament\Concerns\HasDashboardDateRange;
use Capell\Admin\Support\Dashboard\AdminDashboardDataRequestCache;
use Capell\Core\Models\Site;
use Capell\Core\Support\Permissions\SiteAccess;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Override;

final class SiteStatsOverviewFilamentWidget extends StatsOverviewWidget implements CapellFilamentWidgetContract
{
    use GatedByRoleAndSettings;
    use HasDashboardDateRange;

    /**
     * @var list<string>
     */
    protected static array $rolesConfigKeys = [];

    protected static string $settingsKey = 'site_stats_overview';

    /** @var int|string|array<string, int|null> */
    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = 0;

    #[Override]
    protected function getStats(): array
    {
        $data = $this->siteStats();

        return [
            $this->makeWorkQueueStat($data),
            $this->makePublishedStat($data),
            $this->makePendingStat($data),
            $this->makeExpiredStat($data),
        ];
    }

    #[Override]
    protected function getDescription(): string
    {
        $data = $this->siteStats();

        if (! $data->scoped) {
            // A provider that predates scoped statistics ignores the site filter and states no window.
            return implode(' ', [$this->legacyScopeDescription(), __('capell-admin::dashboard.window_current_counts')]);
        }

        return implode(' ', [
            $this->scopeDescription(),
            ResolveDashboardPeriodWindowsAction::make()->describePublishedRolling($this->getDashboardPeriod()),
            __('capell-admin::dashboard.window_current_counts'),
        ]);
    }

    private function siteStats(): SiteStatsData
    {
        return resolve(AdminDashboardDataRequestCache::class)->siteStats($this->getDashboardPeriod(), $this->getDashboardSiteId());
    }

    private function selectedSite(): ?Site
    {
        $siteId = $this->getDashboardSiteId();

        return $siteId === null || $siteId < 1
            ? null
            : SiteAccess::current()->query(Site::class)->find($siteId);
    }

    /**
     * The counts follow the dashboard site selection; an unavailable site is
     * named as such rather than silently widened to every site.
     */
    private function scopeDescription(): string
    {
        $siteId = $this->getDashboardSiteId();

        if ($siteId === null) {
            return (string) __('capell-admin::dashboard.scope_applied_all_sites');
        }

        $site = $this->selectedSite();

        return $site instanceof Site
            ? (string) __('capell-admin::dashboard.scope_applied_site', ['site' => $site->name])
            : (string) __('capell-admin::dashboard.scope_site_unavailable');
    }

    /**
     * A provider without scoped support always counts every accessible site.
     */
    private function legacyScopeDescription(): string
    {
        $site = $this->selectedSite();

        return $site instanceof Site
            ? (string) __('capell-admin::dashboard.scope_other_site', ['site' => $site->name])
            : (string) __('capell-admin::dashboard.scope_all_sites');
    }

    private function makeWorkQueueStat(SiteStatsData $data): Stat
    {
        return Stat::make(__('capell-admin::dashboard.stat_work_queue'), (string) $data->workQueueCount)
            ->color($data->workQueueCount > 0 ? 'warning' : 'success');
    }

    private function makePublishedStat(SiteStatsData $data): Stat
    {
        return Stat::make(__('capell-admin::dashboard.stat_published'), (string) $data->publishedCount)
            ->chart(array_map(static fn (int $value): float => (float) $value, $data->sparklinePublished))
            ->color('primary');
    }

    private function makePendingStat(SiteStatsData $data): Stat
    {
        return Stat::make(__('capell-admin::dashboard.stat_scheduled'), (string) $data->pendingCount)
            ->color($data->pendingCount > 0 ? 'warning' : 'success');
    }

    private function makeExpiredStat(SiteStatsData $data): Stat
    {
        return Stat::make(__('capell-admin::dashboard.stat_expired'), (string) $data->expiredCount)
            ->color($data->expiredCount > 0 ? 'gray' : 'success');
    }
}
