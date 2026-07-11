<?php

declare(strict_types=1);

namespace Capell\Admin\Data\Dashboard;

use Spatie\LaravelData\Data;

final class CapellOverviewStatData extends Data
{
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $value,
        public readonly string $group,
        public readonly ?string $description = null,
        public readonly ?string $url = null,
        public readonly ?string $color = null,
        public readonly int $sort = 100,
    ) {}
}
