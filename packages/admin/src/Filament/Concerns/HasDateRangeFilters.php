<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Concerns;

use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use DateTimeInterface;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\On;

/**
 * @property string|null $filter
 */
trait HasDateRangeFilters
{
    use InteractsWithPageFilters;

    #[On('dashboardFilterChanged')]
    public function onDashboardFilterChanged(string $period): void
    {
        $this->filter = match ($period) {
            'today' => 'today',
            'this_week' => 'week',
            'this_month' => 'month',
            'this_year' => 'year',
            default => 'month',
        };
    }

    /** @return Collection<int, string> */
    protected function getDateLabels(): Collection
    {
        $filter = $this->getActiveDateRangeFilter();
        $now = CarbonImmutable::instance(Date::now());

        /** @var Collection<int, string> $labels */
        $labels = match ($filter) {
            'today' => collect(range(0, 23))->map(fn (int $h): string => sprintf('%02d:00', $h)),
            'yesterday' => collect(range(0, 23))->map(fn (int $h): string => sprintf('%02d:00', $h)),
            'week' => $this->generateDayAbbrevLabels($now->startOfWeek(), $now->endOfWeek()),
            'last_week' => $this->generateDayAbbrevLabels($now->subWeek()->startOfWeek(), $now->subWeek()->endOfWeek()),
            'month' => $this->generateDayMonthLabels($now->startOfMonth(), $now->endOfMonth()),
            'last_month' => $this->generateDayMonthLabels($now->subMonth()->startOfMonth(), $now->subMonth()->endOfMonth()),
            'last_year' => $this->generateMonthYearLabels($now->subYear()->startOfYear(), $now->subYear()->endOfYear()),
            default => $this->generateRolling12MonthLabels($now),
        };

        return $labels;
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    protected function getDateRange(): array
    {
        $filter = $this->getActiveDateRangeFilter();
        $now = CarbonImmutable::instance(Date::now());

        return match ($filter) {
            'today' => [$now->startOfDay(), $now->endOfDay()],
            'yesterday' => [$now->subDay()->startOfDay(), $now->subDay()->endOfDay()],
            'week' => [$now->startOfWeek(), $now->endOfWeek()],
            'last_week' => [$now->subWeek()->startOfWeek(), $now->subWeek()->endOfWeek()],
            'month' => [$now->startOfMonth(), $now->endOfMonth()],
            'last_month' => [$now->subMonth()->startOfMonth(), $now->subMonth()->endOfMonth()],
            'last_year' => [$now->subYear()->startOfYear(), $now->subYear()->endOfYear()],
            default => [$now->subMonths(11)->startOfMonth(), $now->endOfMonth()],
        };
    }

    protected function getFilterLabel(): ?string
    {
        return $this->getFilters()[$this->getActiveDateRangeFilter()] ?? null;
    }

    /** @return array<string, string> */
    protected function getFilters(): ?array
    {
        return [
            'today' => __('capell-admin::generic.today'),
            'yesterday' => __('capell-admin::generic.yesterday'),
            'week' => __('capell-admin::generic.this_week'),
            'last_week' => __('capell-admin::generic.last_week'),
            'month' => __('capell-admin::generic.this_month'),
            'last_month' => __('capell-admin::generic.last_month'),
            'year' => __('capell-admin::generic.this_year'),
            'last_year' => __('capell-admin::generic.last_year'),
        ];
    }

    protected function getSelectRange(string $column): string
    {
        $isSqlite = DB::getDriverName() === 'sqlite';
        $filter = $this->getActiveDateRangeFilter();

        if ($isSqlite) {
            $dayAbbrevCase = "CASE strftime('%%w', %s) WHEN '0' THEN 'Sun' WHEN '1' THEN 'Mon' WHEN '2' THEN 'Tue' WHEN '3' THEN 'Wed' WHEN '4' THEN 'Thu' WHEN '5' THEN 'Fri' WHEN '6' THEN 'Sat' END";
            $monthAbbrevCase = "CASE strftime('%%m', %s) WHEN '01' THEN 'Jan' WHEN '02' THEN 'Feb' WHEN '03' THEN 'Mar' WHEN '04' THEN 'Apr' WHEN '05' THEN 'May' WHEN '06' THEN 'Jun' WHEN '07' THEN 'Jul' WHEN '08' THEN 'Aug' WHEN '09' THEN 'Sep' WHEN '10' THEN 'Oct' WHEN '11' THEN 'Nov' WHEN '12' THEN 'Dec' END";
            $yearShort = "substr(strftime('%%Y', %s), 3, 2)";

            return match ($filter) {
                'today', 'yesterday' => sprintf("strftime('%%H:00', %s)", $column),
                'week', 'last_week' => sprintf($dayAbbrevCase, $column),
                'month', 'last_month' => sprintf("strftime('%%d', %s) || ' ' || %s", $column, sprintf($monthAbbrevCase, $column)),
                'last_year', 'year' => sprintf("%s || ' ' || %s", sprintf($monthAbbrevCase, $column), sprintf($yearShort, $column)),
                default => sprintf("%s || ' ' || %s", sprintf($monthAbbrevCase, $column), sprintf($yearShort, $column)),
            };
        }

        return match ($filter) {
            'today', 'yesterday' => sprintf("DATE_FORMAT(%s, '%%H:00')", $column),
            'week', 'last_week' => sprintf("DATE_FORMAT(%s, '%%a')", $column),
            'month', 'last_month' => sprintf("DATE_FORMAT(%s, '%%d %%b')", $column),
            'last_year', 'year' => sprintf("DATE_FORMAT(%s, '%%b %%y')", $column),
            default => sprintf("DATE_FORMAT(%s, '%%b %%y')", $column),
        };
    }

    // Helpers
    /** @return Collection<int, string> */
    private function generateDayAbbrevLabels(CarbonImmutable $start, CarbonImmutable $end): Collection
    {
        /** @var array<int, DateTimeInterface> $dates */
        $dates = CarbonPeriod::create($start, $end)->toArray();

        /** @var Collection<int, string> $labels */
        $labels = collect($dates)->map(fn (DateTimeInterface $date): string => CarbonImmutable::instance($date)->format('D'))->values();

        return $labels;
    }

    /** @return Collection<int, string> */
    private function generateDayMonthLabels(CarbonImmutable $start, CarbonImmutable $end): Collection
    {
        /** @var array<int, DateTimeInterface> $dates */
        $dates = CarbonPeriod::create($start, $end)->toArray();

        /** @var Collection<int, string> $labels */
        $labels = collect($dates)->map(fn (DateTimeInterface $date): string => CarbonImmutable::instance($date)->format('d M'))->values();

        return $labels;
    }

    /** @return Collection<int, string> */
    private function generateMonthYearLabels(CarbonImmutable $start, CarbonImmutable $end): Collection
    {
        /** @var Collection<int, string> $months */
        $months = collect();
        $current = $start->startOfMonth();
        while ($current <= $end) {
            $months->push($current->format('M y'));
            $current = $current->addMonth();
        }

        return $months->values();
    }

    /** @return Collection<int, string> */
    private function generateRolling12MonthLabels(CarbonImmutable $now): Collection
    {
        $start = $now->subMonths(11)->startOfMonth();
        $end = $now->endOfMonth();

        return $this->generateMonthYearLabels($start, $end);
    }

    private function getActiveDateRangeFilter(): string
    {
        $period = data_get($this->pageFilters, 'date_range');

        if (is_string($period) && $period !== '') {
            return match ($period) {
                'today' => 'today',
                'this_week' => 'week',
                'this_month' => 'month',
                'this_year' => 'year',
                default => 'month',
            };
        }

        return $this->filter ?? 'year';
    }
}
