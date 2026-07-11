<?php

declare(strict_types=1);

namespace Capell\Core\Enums;

use BackedEnum;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasDescription;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;

enum RedirectStatusCodeEnum: int implements HasColor, HasDescription, HasIcon, HasLabel
{
    case Permanent = 301;
    case Temporary = 302;

    public function getColor(): string
    {
        return match ($this) {
            self::Permanent => 'success',
            self::Temporary => 'warning',
        };
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::Permanent => __('capell-core::generic.redirect_permanent_description'),
            self::Temporary => __('capell-core::generic.redirect_temporary_description'),
        };
    }

    public function getIcon(): BackedEnum
    {
        return match ($this) {
            self::Permanent => Heroicon::ArrowRight,
            self::Temporary => Heroicon::OutlinedArrowRight,
        };
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::Permanent => __('capell-core::generic.redirect_301'),
            self::Temporary => __('capell-core::generic.redirect_302'),
        };
    }
}
