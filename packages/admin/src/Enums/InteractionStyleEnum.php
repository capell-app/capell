<?php

declare(strict_types=1);

namespace Capell\Admin\Enums;

use Filament\Support\Contracts\HasLabel;

enum InteractionStyleEnum: string implements HasLabel
{
    case Primary = 'primary';
    case Secondary = 'secondary';
    case Subtle = 'subtle';

    public function getLabel(): string
    {
        return match ($this) {
            self::Primary => (string) __('capell-admin::generic.primary'),
            self::Secondary => (string) __('capell-admin::generic.secondary'),
            self::Subtle => (string) __('capell-admin::generic.subtle'),
        };
    }
}
