<?php

declare(strict_types=1);

namespace Capell\Admin\Data\Metrics;

use Spatie\LaravelData\Data;

final class SiteAdminMetricTrendPointData extends Data
{
    public function __construct(
        public readonly string $day,
        public readonly string $value,
        public readonly string $heightClass,
    ) {}
}
