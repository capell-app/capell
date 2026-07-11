<?php

declare(strict_types=1);

namespace Capell\Admin\Enums;

use Filament\Support\Contracts\HasLabel;

enum ThemeStudioHeadingScaleEnum: string implements HasLabel
{
    case Compact = 'compact';
    case Balanced = 'balanced';
    case Expressive = 'expressive';

    public function getLabel(): string
    {
        return match ($this) {
            self::Compact => (string) __('capell-admin::form.theme_studio_heading_scale_compact'),
            self::Balanced => (string) __('capell-admin::form.theme_studio_heading_scale_balanced'),
            self::Expressive => (string) __('capell-admin::form.theme_studio_heading_scale_expressive'),
        };
    }
}
