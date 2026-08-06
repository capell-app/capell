<?php

declare(strict_types=1);

namespace Capell\Admin\Data\Dashboard;

use Capell\Admin\Enums\DashboardDateRangeEnum;
use Spatie\LaravelData\Data;

final class DashboardFilterStateData extends Data
{
    /** @param array<string, mixed> $localFilters */
    public function __construct(
        public readonly DashboardDateRangeEnum $period = DashboardDateRangeEnum::ThisWeek,
        public readonly ?string $date = null,
        public readonly ?int $siteId = null,
        public readonly ?string $language = null,
        public readonly bool $refresh = false,
        public readonly array $localFilters = [],
    ) {}
}
