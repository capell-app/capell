<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Dashboard;

use Carbon\CarbonImmutable;
use DateTimeZone;
use Throwable;

final class ValidateDashboardReportingPeriodAction
{
    public function handle(?string $start, ?string $end, string $timezone, int $days, string $status): bool
    {
        if ($start === null || $end === null || $days < 1 || $days > 365
            || ! in_array($timezone, DateTimeZone::listIdentifiers(), true)
            || ! in_array($status, ['available', 'stale'], true)) {
            return false;
        }

        try {
            $periodStart = CarbonImmutable::createFromFormat('!Y-m-d', $start, $timezone);
            $periodEnd = CarbonImmutable::createFromFormat('!Y-m-d', $end, $timezone);
        } catch (Throwable) {
            return false;
        }

        if (! $periodStart instanceof CarbonImmutable || ! $periodEnd instanceof CarbonImmutable
            || $periodStart->toDateString() !== $start || $periodEnd->toDateString() !== $end
            || ! $periodStart->addDays($days)->equalTo($periodEnd)) {
            return false;
        }

        $today = CarbonImmutable::now($timezone)->startOfDay();

        return $status === 'available' ? $periodEnd->equalTo($today) : $periodEnd->lessThanOrEqualTo($today);
    }
}
