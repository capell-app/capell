<?php

declare(strict_types=1);

namespace Capell\Admin\Data;

use BackedEnum;
use Spatie\LaravelData\Data;

final class EnumPresentationData extends Data
{
    /** @param array<int, string>|string|null $color */
    public function __construct(
        public string $label,
        public string|array|null $color = null,
        public string|BackedEnum|null $icon = null,
        public ?string $description = null,
    ) {}
}
