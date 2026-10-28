<?php

declare(strict_types=1);

namespace Capell\Admin\Data\Dashboard;

use Spatie\LaravelData\Data;

final class DashboardVisitorsData extends Data
{
    public function __construct(
        public readonly string $status,
        public readonly string $measure,
        public readonly string $source,
        public readonly string $timezone,
        public readonly ?int $current,
        public readonly ?int $previous,
        public readonly ?string $lastUpdated,
        public readonly ?string $periodStart = null,
        public readonly ?string $periodEnd = null,
    ) {}
}
