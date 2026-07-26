<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Concerns;

use Capell\Core\Data\Database\SqlFragment;
use Capell\Core\Enums\Database\DatabaseDateOperation;
use Capell\Core\Facades\CapellDatabase;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use DateTimeInterface;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;
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
        $filter = $this->getActiveDateRangeFilter();
        $operation = match ($filter) {
            'today', 'yesterday' => DatabaseDateOperation::HourLabel,
            'week', 'last_week' => DatabaseDateOperation::DayAbbreviation,
            'month', 'last_month' => DatabaseDateOperation::DayMonthLabel,
            default => DatabaseDateOperation::MonthYearLabel,
        };

        return CapellDatabase::for()
            ->queryDialect()
            ->date($operation, SqlFragment::raw($column))
            ->sql;
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
