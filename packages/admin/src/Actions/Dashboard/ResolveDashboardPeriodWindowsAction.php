<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Dashboard;

use Capell\Admin\Settings\AdminSettings;
use Carbon\CarbonImmutable;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * The one definition of what each named dashboard period covers.
 *
 * Analytics reads a rolling window of whole days back from now in UTC.
 * Published-page statistics read a calendar window in the application
 * timezone. Both are resolved here so the figures and the text that describes
 * them cannot drift apart.
 */
final class ResolveDashboardPeriodWindowsAction
{
    use AsFake;
    use AsObject;

    /**
     * @return array{CarbonImmutable, CarbonImmutable}
     */
    public function handle(string $period, ?CarbonImmutable $now = null): array
    {
        return $this->calendar($period, $now);
    }

    /**
     * Calendar window in the application timezone.
     *
     * @return array{CarbonImmutable, CarbonImmutable}
     */
    public function calendar(string $period, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();

        return match ($period) {
            'today' => [$now->startOfDay(), $now->endOfDay()],
            'this_week' => [$now->startOfWeek(), $now->endOfWeek()],
            'this_month' => [$now->startOfMonth(), $now->endOfMonth()],
            'this_year' => [$now->startOfYear(), $now->endOfYear()],
            default => [$now->subDays(30)->startOfDay(), $now->endOfDay()],
        };
    }

    /**
     * Number of days in the rolling analytics window ending now (UTC).
     */
    public function rollingDays(string $period): int
    {
        return match ($period) {
            'today' => 1,
            'this_week' => 7,
            'this_month', 'last_30_days' => 30,
            'this_year' => 365,
            default => max(1, min(365, AdminSettings::instance()->analytics_default_period_days)),
        };
    }

    public function describeRolling(string $period): string
    {
        return trans_choice('capell-admin::dashboard.window_rolling', $this->rollingDays($period), [
            'days' => $this->rollingDays($period),
        ]);
    }

    public function describeCalendar(string $period, ?CarbonImmutable $now = null): string
    {
        [$start, $end] = $this->calendar($period, $now);

        return (string) __('capell-admin::dashboard.window_calendar', [
            'start' => $start->translatedFormat('j M Y'),
            'end' => $end->translatedFormat('j M Y'),
            'timezone' => $start->getTimezone()->getName(),
        ]);
    }
}
