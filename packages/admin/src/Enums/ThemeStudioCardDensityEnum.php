<?php

declare(strict_types=1);

namespace Capell\Admin\Enums;

use Filament\Support\Contracts\HasLabel;

enum ThemeStudioCardDensityEnum: string implements HasLabel
{
    case Compact = 'compact';
    case Comfortable = 'comfortable';
    case Spacious = 'spacious';

    public function getLabel(): string
    {
        return match ($this) {
            self::Compact => (string) __('capell-admin::form.theme_studio_card_density_compact'),
            self::Comfortable => (string) __('capell-admin::form.theme_studio_card_density_comfortable'),
            self::Spacious => (string) __('capell-admin::form.theme_studio_card_density_spacious'),
        };
    }
}
