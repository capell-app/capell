<?php

declare(strict_types=1);

namespace Capell\Admin\Data\Dashboard;

use Spatie\LaravelData\Data;

final class TrafficDayData extends Data
{
    public function __construct(
        public readonly string $date,
        public readonly int $visitors,
        public readonly int $pageviews,
    ) {}
}
