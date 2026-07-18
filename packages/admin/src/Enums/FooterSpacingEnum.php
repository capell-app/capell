<?php

declare(strict_types=1);

namespace Capell\Admin\Enums;

use Filament\Support\Contracts\HasLabel;

enum FooterSpacingEnum: string implements HasLabel
{
    case Compact = 'compact';
    case Default = 'default';
    case Comfortable = 'comfortable';

    public function getLabel(): string
    {
        return match ($this) {
            self::Compact => (string) __('capell-admin::form.spacing_compact'),
            self::Default => (string) __('capell-admin::generic.default'),
            self::Comfortable => (string) __('capell-admin::form.spacing_comfortable'),
        };
    }
}
