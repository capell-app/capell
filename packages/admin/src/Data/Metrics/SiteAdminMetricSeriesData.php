<?php

declare(strict_types=1);

namespace Capell\Admin\Data\Metrics;

use Spatie\LaravelData\Data;

final class SiteAdminMetricSeriesData extends Data
{
    /**
     * @param  list<SiteAdminMetricTrendPointData>  $points
     */
    public function __construct(
        public readonly string $label,
        public readonly string $description,
        public readonly string $latestValue,
        public readonly array $points,
    ) {}
}
