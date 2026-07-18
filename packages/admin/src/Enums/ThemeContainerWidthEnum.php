<?php

declare(strict_types=1);

namespace Capell\Admin\Enums;

use Capell\Core\Enums\Concerns\HasEnumOptions;
use Filament\Support\Contracts\HasLabel;

enum ThemeContainerWidthEnum: string implements HasLabel
{
    use HasEnumOptions;

    case Small = 'sm';
    case Medium = 'md';
    case Large = 'lg';

    public function getLabel(): string
    {
        return match ($this) {
            self::Small => (string) __('capell-admin::generic.sm'),
            self::Medium => (string) __('capell-admin::generic.md'),
            self::Large => (string) __('capell-admin::generic.lg'),
        };
    }
}
