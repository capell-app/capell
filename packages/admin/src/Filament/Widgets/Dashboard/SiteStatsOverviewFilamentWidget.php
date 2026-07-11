<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Widgets\Dashboard;

use Capell\Admin\Contracts\CapellFilamentWidgetContract;
use Capell\Admin\Data\Dashboard\SiteStatsData;
use Capell\Admin\Filament\Concerns\GatedByRoleAndSettings;
use Capell\Admin\Filament\Concerns\HasDashboardDateRange;
use Capell\Admin\Support\Dashboard\AdminDashboardDataRequestCache;
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
        $data = resolve(AdminDashboardDataRequestCache::class)->siteStats($this->getDashboardPeriod());

        return [
            $this->makeWorkQueueStat($data),
            $this->makePublishedStat($data),
            $this->makePendingStat($data),
            $this->makeExpiredStat($data),
        ];
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
