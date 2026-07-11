<?php

declare(strict_types=1);

namespace Capell\Core\Enums;

use BackedEnum;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasDescription;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;

enum UrlTypeEnum: string implements HasColor, HasDescription, HasIcon, HasLabel
{
    case Alias = 'alias';
    case Redirect = 'redirect';

    public function getColor(): string
    {
        return match ($this) {
            self::Alias => 'secondary',
            self::Redirect => 'info',
        };
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::Alias => __('capell::generic.alias_description'),
            self::Redirect => __('capell::generic.redirect_description'),
        };
    }

    public function getIcon(): BackedEnum
    {
        return match ($this) {
            self::Alias => Heroicon::Link,
            self::Redirect => Heroicon::OutlinedArrowPathRoundedSquare,
        };
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::Alias => __('capell::generic.alias'),
            self::Redirect => __('capell::generic.redirect'),
        };
    }
}
