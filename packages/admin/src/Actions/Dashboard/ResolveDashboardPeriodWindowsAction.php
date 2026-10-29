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
 * Analytics and site statistics both read a rolling window of whole days back
 * from now in UTC. The window and the text that describes it are resolved here
 * so the figures and the wording cannot drift apart.
 */
final class ResolveDashboardPeriodWindowsAction
{
    use AsFake;
    use AsObject;

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

    /**
     * The rolling window ending now (UTC) that analytics and site statistics share.
     *
     * @return array{CarbonImmutable, CarbonImmutable}
     */
    public function handle(string $period, ?CarbonImmutable $now = null): array
    {
        $end = ($now ?? CarbonImmutable::now())->utc();

        return [$end->subDays($this->rollingDays($period)), $end];
    }

    public function describePublishedRolling(string $period): string
    {
        return trans_choice('capell-admin::dashboard.window_published_rolling', $this->rollingDays($period), [
            'days' => $this->rollingDays($period),
        ]);
    }

    public function describeRolling(string $period): string
    {
        return trans_choice('capell-admin::dashboard.window_rolling', $this->rollingDays($period), [
            'days' => $this->rollingDays($period),
        ]);
    }
}
