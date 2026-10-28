<?php

declare(strict_types=1);

namespace Capell\Admin\Data\Dashboard;

use Spatie\LaravelData\Data;

final class DashboardPageViewsData extends Data
{
    /** @param list<array{day: string, count: int}> $trend */
    public function __construct(
        public readonly string $status,
        public readonly ?int $current,
        public readonly ?int $previous,
        public readonly array $trend,
        public readonly ?string $lastUpdated,
        public readonly int $sitesCovered,
        public readonly int $sitesRequested,
    ) {}
}
