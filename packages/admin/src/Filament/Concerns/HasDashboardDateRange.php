<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Concerns;

use Carbon\CarbonImmutable;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Livewire\Attributes\On;

trait HasDashboardDateRange
{
    use InteractsWithPageFilters;

    public string $dashboardPeriod = 'this_week';

    #[On('dashboardFilterChanged')]
    public function onDashboardFilterChanged(string $period): void
    {
        $this->dashboardPeriod = $period;
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

    protected function hasDashboardPeriodFilter(): bool
    {
        $period = data_get($this->pageFilters, 'date_range');

        return (is_string($period) && $period !== '')
            || $this->dashboardPeriod !== 'this_week';
    }
}
