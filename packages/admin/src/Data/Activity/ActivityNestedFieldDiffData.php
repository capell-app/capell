<?php

declare(strict_types=1);

namespace Capell\Admin\Data\Activity;

use Spatie\LaravelData\Data;

final class ActivityNestedFieldDiffData extends Data
{
    public function __construct(
        public readonly string $path,
        public readonly string $label,
        public readonly string $status,
        public readonly string $beforeSummary,
        public readonly string $afterSummary,
        public readonly string $beforeDetail,
        public readonly string $afterDetail,
    ) {}
}
