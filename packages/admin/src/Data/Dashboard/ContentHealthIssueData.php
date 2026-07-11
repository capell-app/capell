<?php

declare(strict_types=1);

namespace Capell\Admin\Data\Dashboard;

use Spatie\LaravelData\Data;

final class ContentHealthIssueData extends Data
{
    public function __construct(
        public readonly string $id,
        public readonly string $label,
        public readonly int $count,
        public readonly ?string $filterUrl,
    ) {}
}
