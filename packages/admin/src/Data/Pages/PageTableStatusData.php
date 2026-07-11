<?php

declare(strict_types=1);

namespace Capell\Admin\Data\Pages;

use BackedEnum;
use Carbon\CarbonImmutable;
use Spatie\LaravelData\Data;

class PageTableStatusData extends Data
{
    public function __construct(
        public readonly string $label,
        public readonly string $shortLabel,
        public readonly ?string $tooltip,
        public readonly string $color,
        public readonly BackedEnum|string|null $icon,
        public readonly ?CarbonImmutable $date = null,
    ) {}
}
