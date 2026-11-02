<?php

declare(strict_types=1);

namespace Capell\Admin\Data\Extensions;

use Spatie\LaravelData\Data;

final class ExtensionNavigationBadgeCountsData extends Data
{
    public function __construct(
        public readonly int $unhealthyCount,
        public readonly int $blockedCount,
    ) {}
}
