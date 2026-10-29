<?php

declare(strict_types=1);

namespace Capell\Admin\Data\Dashboard;

use Carbon\CarbonImmutable;
use Spatie\LaravelData\Data;

final class DashboardAnalyticsPeriodData extends Data
{
    public function __construct(
        public readonly CarbonImmutable $start,
        public readonly CarbonImmutable $end,
        public readonly CarbonImmutable $previousStart,
        public readonly CarbonImmutable $previousEnd,
        public readonly string $timezone,
    ) {}
}
