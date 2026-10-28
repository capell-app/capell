<?php

declare(strict_types=1);

namespace Capell\Admin\Data\Dashboard;

use Spatie\LaravelData\Data;

final class DashboardWorkData extends Data
{
    /** @param list<DashboardWorkItemData> $items */
    public function __construct(
        public readonly bool $available,
        public readonly int $total,
        public readonly array $items,
        public readonly ?string $listUrl = null,
    ) {}
}
