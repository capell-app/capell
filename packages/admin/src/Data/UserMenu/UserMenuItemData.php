<?php

declare(strict_types=1);

namespace Capell\Admin\Data\UserMenu;

use Closure;
use Filament\Support\Icons\Heroicon;
use Spatie\LaravelData\Data;

final class UserMenuItemData extends Data
{
    public function __construct(
        public readonly string $key,
        public readonly string|Closure $label,
        public readonly string|Heroicon|null $icon = null,
        public readonly string|Closure|null $url = null,
        public readonly int|string|Closure|null $badge = null,
        public readonly string|Closure|null $badgeColor = null,
        public readonly bool|Closure $visible = true,
        public readonly int $sort = 100,
        public readonly ?string $group = null,
    ) {}
}
