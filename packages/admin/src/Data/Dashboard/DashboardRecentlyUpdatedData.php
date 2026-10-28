<?php

declare(strict_types=1);

namespace Capell\Admin\Data\Dashboard;

use Spatie\LaravelData\Data;

final class DashboardRecentlyUpdatedData extends Data
{
    /** @param list<DashboardRecentlyUpdatedItemData> $items */
    public function __construct(
        public readonly int $total,
        public readonly array $items,
    ) {}
}
