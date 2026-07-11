<?php

declare(strict_types=1);

namespace Capell\Admin\Enums;

use Filament\Support\Contracts\HasLabel;

enum ThemeStudioOverlayTreatmentEnum: string implements HasLabel
{
    case None = 'none';
    case Subtle = 'subtle';
    case Strong = 'strong';

    public function getLabel(): string
    {
        return match ($this) {
            self::None => (string) __('capell-admin::form.theme_studio_overlay_treatment_none'),
            self::Subtle => (string) __('capell-admin::form.theme_studio_overlay_treatment_subtle'),
            self::Strong => (string) __('capell-admin::form.theme_studio_overlay_treatment_strong'),
        };
    }
}
