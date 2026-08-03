<?php

declare(strict_types=1);

namespace Capell\Admin\Data;

use BackedEnum;
use Illuminate\Contracts\Support\Htmlable;
use Spatie\LaravelData\Data;

final class RecordStateData extends Data
{
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly ?string $shortLabel = null,
        public readonly ?string $description = null,
        public readonly string $color = 'gray',
        public readonly BackedEnum|string|Htmlable|null $icon = null,
        public readonly int $priority = 100,
        public readonly bool $isExceptional = true,
    ) {}
}
