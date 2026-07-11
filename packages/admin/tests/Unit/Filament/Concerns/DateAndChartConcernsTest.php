<?php

declare(strict_types=1);

use Capell\Admin\Filament\Concerns\HasDashboardDateRange;
use Capell\Admin\Filament\Concerns\HasDateRangeFilters;
use Capell\Admin\Filament\Concerns\HasLineChartOptions;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;

afterEach(function (): void {
    Date::setTestNow();
    CarbonImmutable::setTestNow();
});

it('maps dashboard events into date range filters and labels', function (): void {
    Date::setTestNow(CarbonImmutable::parse('2026-05-07 12:00:00'));

    $subject = new class
    {
        use HasDateRangeFilters;

        public ?string $filter = null;

        /** @return array<int, string> */
        public function labels(): array
        {
            return $this->getDateLabels()->all();
        }

        /** @return array<int, string> */
        public function range(): array
        {
            return array_map(
                fn (CarbonImmutable $date): string => $date->toDateTimeString(),
                $this->getDateRange(),
            );
        }

        public function label(): ?string
        {
            return $this->getFilterLabel();
        }

        public function selectRange(string $column): string
        {
            return $this->getSelectRange($column);
        }
    };

    $subject->onDashboardFilterChanged('today');

    expect($subject->filter)->toBe('today')
        ->and(array_slice($subject->labels(), 0, 2))->toBe(['00:00', '01:00'])
        ->and($subject->range())->toBe(['2026-05-07 00:00:00', '2026-05-07 23:59:59'])
        ->and($subject->label())->toBe(__('capell-admin::generic.today'))
        ->and($subject->selectRange('created_at'))->toContain("strftime('%H:00', created_at)");

    $subject->onDashboardFilterChanged('this_week');
    expect($subject->filter)->toBe('week')
        ->and($subject->labels())->toBe(['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun']);

    $subject->filter = 'yesterday';
    expect(array_slice($subject->labels(), 22, 2))->toBe(['22:00', '23:00'])
        ->and($subject->range())->toBe(['2026-05-06 00:00:00', '2026-05-06 23:59:59']);

    $subject->filter = 'last_week';
    expect($subject->range())->toBe(['2026-04-27 00:00:00', '2026-05-03 23:59:59'])
        ->and($subject->selectRange('created_at'))->toContain('CASE strftime');

    $subject->pageFilters = ['date_range' => 'this_month'];
    expect($subject->range())->toBe(['2026-05-01 00:00:00', '2026-05-31 23:59:59']);
    $subject->pageFilters = null;

    $subject->filter = 'month';
    expect($subject->labels()[0])->toBe('01 May')
        ->and($subject->selectRange('created_at'))->toContain("strftime('%d', created_at)");

    $subject->filter = 'last_month';
    expect($subject->labels()[0])->toBe('01 Apr')
        ->and($subject->range())->toBe(['2026-04-01 00:00:00', '2026-04-30 23:59:59']);

    $subject->filter = 'last_year';
    expect($subject->labels()[0])->toBe('Jan 25')
        ->and($subject->range())->toBe(['2025-01-01 00:00:00', '2025-12-31 23:59:59']);

    $subject->filter = 'year';
    expect($subject->labels())->toHaveCount(12)
        ->and($subject->labels()[0])->toBe('Jun 25');

    $subject->pageFilters = ['date_range' => 'unsupported'];
    expect($subject->label())->toBe(__('capell-admin::generic.this_month'));
});

it('calculates dashboard date ranges from selected period', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-07 12:00:00'));

    $subject = new class
    {
        use HasDashboardDateRange;

        /** @return array<int, string> */
        public function range(int $fallbackDays = 30): array
        {
            return array_map(
                fn (CarbonImmutable $date): string => $date->toDateTimeString(),
                $this->getDashboardDateRange($fallbackDays),
            );
        }
    };

    $subject->onDashboardFilterChanged('today');

    expect($subject->range())->toBe(['2026-05-07 00:00:00', '2026-05-07 23:59:59']);

    $subject->pageFilters = ['date_range' => 'this_month'];
    expect($subject->range())->toBe(['2026-05-01 00:00:00', '2026-05-31 23:59:59']);

    $subject->onDashboardFilterChanged('this_year');
    $subject->pageFilters = null;

    expect($subject->range())->toBe(['2026-01-01 00:00:00', '2026-12-31 23:59:59']);

    $subject->onDashboardFilterChanged('custom');
    expect($subject->range(7))->toBe(['2026-04-30 00:00:00', '2026-05-07 23:59:59']);
});

it('provides standard line chart options', function (): void {
    $subject = new class
    {
        use HasLineChartOptions;

        /** @return array<string, mixed> */
        public function options(): array
        {
            return $this->getOptions();
        }

        public function type(): string
        {
            return $this->getType();
        }
    };

    $options = $subject->options();

    expect($subject->type())->toBe('line')
        ->and($options['responsive'])->toBeTrue()
        ->and($options['maintainAspectRatio'])->toBeFalse()
        ->and($options['plugins']['legend']['display'])->toBeFalse()
        ->and($options['plugins']['tooltip']['enabled'])->toBeTrue()
        ->and($options['scales']['x']['grid']['display'])->toBeFalse()
        ->and($options['scales']['y'])->toMatchArray([
            'beginAtZero' => true,
            'min' => 0,
            'max' => 10,
        ]);
});
