<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Concerns;

use Capell\Admin\Settings\AdminSettings;
use Carbon\CarbonImmutable;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Livewire\Attributes\On;

trait HasDashboardDateRange
{
    use InteractsWithPageFilters;

    public string $dashboardPeriod = 'this_week';

    public ?int $dashboardSiteId = null;

    public ?string $dashboardLanguage = null;

    #[On('dashboardFilterChanged')]
    public function onDashboardFilterChanged(string $period, ?int $siteId = null, ?string $language = null, bool $refresh = false): void
    {
        $this->dashboardPeriod = $period;
        $this->dashboardSiteId = $siteId;
        $this->dashboardLanguage = $language;
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    protected function getDashboardDateRange(int $fallbackDays = 30): array
    {
        $now = CarbonImmutable::now();

        return match ($this->getDashboardPeriod()) {
            'today' => [$now->startOfDay(), $now->endOfDay()],
            'this_week' => [$now->startOfWeek(), $now->endOfWeek()],
            'this_month' => [$now->startOfMonth(), $now->endOfMonth()],
            'this_year' => [$now->startOfYear(), $now->endOfYear()],
            default => [$now->subDays($fallbackDays)->startOfDay(), $now->endOfDay()],
        };
    }

    protected function getDashboardPeriod(): string
    {
        $period = data_get($this->pageFilters, 'date_range');

        return is_string($period) && $period !== ''
            ? $period
            : $this->dashboardPeriod;
    }

    protected function getDashboardSiteId(): ?int
    {
        $siteId = data_get($this->pageFilters, 'site_id');

        return is_numeric($siteId) && (int) $siteId > 0 ? (int) $siteId : $this->dashboardSiteId;
    }

    protected function getDashboardLanguage(): ?string
    {
        $language = data_get($this->pageFilters, 'language');

        return is_string($language) && $language !== '' ? $language : $this->dashboardLanguage;
    }

    protected function getPollingInterval(): ?string
    {
        $seconds = max(0, min(3600, AdminSettings::instance()->analytics_refresh_interval_seconds));

        return $seconds > 0 ? $seconds . 's' : null;
    }

    protected function hasDashboardPeriodFilter(): bool
    {
        $period = data_get($this->pageFilters, 'date_range');

        return (is_string($period) && $period !== '')
            || $this->dashboardPeriod !== 'this_week';
    }
}
