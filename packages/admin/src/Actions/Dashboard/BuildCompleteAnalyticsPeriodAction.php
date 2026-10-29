<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Dashboard;

use Capell\Admin\Data\Dashboard\DashboardAnalyticsPeriodData;
use Carbon\CarbonImmutable;
use DateTimeZone;
use InvalidArgumentException;

final class BuildCompleteAnalyticsPeriodAction
{
    public function handle(CarbonImmutable $now, int $days, string $timezone): DashboardAnalyticsPeriodData
    {
        throw_if($days < 1 || $days > 365 || ! in_array($timezone, DateTimeZone::listIdentifiers(), true), InvalidArgumentException::class, 'Invalid analytics period or reporting time zone.');

        $end = $now->setTimezone($timezone)->startOfDay();
        $start = $end->subDays($days);

        return new DashboardAnalyticsPeriodData(
            start: $start,
            end: $end,
            previousStart: $start->subDays($days),
            previousEnd: $start,
            timezone: $timezone,
        );
    }
}
