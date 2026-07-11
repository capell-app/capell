<?php

declare(strict_types=1);

namespace Capell\Admin\Data\Activity;

use Spatie\LaravelData\Data;

final class ActivityFieldDiffData extends Data
{
    /**
     * @param  list<ActivityNestedFieldDiffData>  $nestedChanges
     */
    public function __construct(
        public readonly string $path,
        public readonly string $label,
        public readonly string $status,
        public readonly bool $reversible,
        public readonly ?string $skipReason,
        public readonly string $beforeSummary,
        public readonly string $afterSummary,
        public readonly string $beforeDetail,
        public readonly string $afterDetail,
        public readonly array $nestedChanges,
        public readonly int $hiddenNestedChangeCount,
    ) {}
}
