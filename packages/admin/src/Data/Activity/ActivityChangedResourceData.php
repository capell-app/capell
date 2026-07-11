<?php

declare(strict_types=1);

namespace Capell\Admin\Data\Activity;

use Spatie\LaravelData\Data;

final class ActivityChangedResourceData extends Data
{
    public function __construct(
        public readonly ?string $morphType,
        public readonly ?string $modelClass,
        public readonly ?string $stableIdentifier,
        public readonly string $label,
        public readonly ?string $url,
        public readonly string $area,
        public readonly ?string $package,
        public readonly int $changedFieldCount,
    ) {}
}
