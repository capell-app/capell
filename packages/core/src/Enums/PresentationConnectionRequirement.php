<?php

declare(strict_types=1);

namespace Capell\Core\Enums;

use Capell\Core\Enums\Concerns\HasEnumOptions;
use Filament\Support\Contracts\HasLabel;

enum PresentationConnectionRequirement: string implements HasLabel
{
    use HasEnumOptions;

    case Any = 'any';
    case FastOnly = 'fast_only';
    case HideOnSaveData = 'hide_on_save_data';

    public function getLabel(): string
    {
        return match ($this) {
            self::Any => (string) __('capell::generic.presentation_connection_any'),
            self::FastOnly => (string) __('capell::generic.fast_only'),
            self::HideOnSaveData => (string) __('capell::generic.hide_on_save_data'),
        };
    }
}
