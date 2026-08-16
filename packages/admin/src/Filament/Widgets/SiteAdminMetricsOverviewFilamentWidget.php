<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Widgets;

use Capell\Admin\Actions\Metrics\ReadSiteAdminMetricSeriesAction;
use Capell\Admin\Data\Metrics\SiteAdminMetricSeriesData;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Contracts\Auth\Authenticatable;
use Override;

final class SiteAdminMetricsOverviewFilamentWidget extends StatsOverviewWidget
{
    protected static bool $isLazy = false;

    protected ?string $pollingInterval = null;

    protected int|string|array $columnSpan = 'full';

    #[Override]
    protected function getStats(): array
    {
        $actor = auth()->user();

        if (! $actor instanceof Authenticatable) {
            return [];
        }

        $stats = [];

        foreach (ReadSiteAdminMetricSeriesAction::run($actor) as $index => $series) {
            $stats[] = $this->makeStat($series, $index);
        }

        return $stats;
    }

    private function makeStat(SiteAdminMetricSeriesData $series, int $index): Stat
    {
        $stat = Stat::make($series->label, $series->latestValue)
            ->description($series->description)
            ->color('primary')
            ->key('site-admin-metric-' . $index);

        if ($series->trend !== []) {
            $stat->chart($series->trend);
        }

        return $stat;
    }
}
