<?php

declare(strict_types=1);

namespace Capell\Admin\Enums;

use Capell\Core\Enums\Concerns\HasEnumOptions;
use Filament\Support\Contracts\HasLabel;

enum ThemeStudioRadiusEnum: string implements HasLabel
{
    use HasEnumOptions;

    case None = 'none';
    case Small = 'sm';
    case Medium = 'md';
    case Large = 'lg';
    case ExtraLarge = 'xl';

    public function getLabel(): string
    {
        return match ($this) {
            self::None => (string) __('capell-admin::form.theme_studio_radius_none'),
            self::Small => (string) __('capell-admin::form.theme_studio_radius_sm'),
            self::Medium => (string) __('capell-admin::form.theme_studio_radius_md'),
            self::Large => (string) __('capell-admin::form.theme_studio_radius_lg'),
            self::ExtraLarge => (string) __('capell-admin::form.theme_studio_radius_xl'),
        };
    }
}
