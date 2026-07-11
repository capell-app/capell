<?php

declare(strict_types=1);

namespace Capell\Admin\Enums\Themes;

use Filament\Support\Contracts\HasLabel;

enum ThemeActivationScope: string implements HasLabel
{
    case Global = 'global';

    case SelectedSites = 'selected_sites';

    public function getLabel(): string
    {
        return match ($this) {
            self::Global => __('capell-admin::form.theme_activation_global'),
            self::SelectedSites => __('capell-admin::form.theme_activation_selected_sites'),
        };
    }
}
