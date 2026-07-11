<?php

declare(strict_types=1);

namespace Capell\Admin\Data\Dashboard;

use Spatie\LaravelData\Data;

final class SiteTrafficData extends Data
{
    /**
     * @param  array<int, TrafficDayData>  $days
     */
    public function __construct(
        public readonly array $days,
        public readonly int $totalVisitors,
        public readonly int $totalPageviews,
        public readonly int $windowDays,
        public readonly string $bucket,
    ) {}
}
