<?php

declare(strict_types=1);

namespace Capell\Admin\Enums;

use Filament\Support\Contracts\HasLabel;

enum PageHeroStyleEnum: string implements HasLabel
{
    case Default = 'default';
    case Editorial = 'editorial';
    case Immersive = 'immersive';
    case Compact = 'compact';

    public function getLabel(): string
    {
        return match ($this) {
            self::Default => (string) __('capell-admin::form.hero_style_default'),
            self::Editorial => (string) __('capell-admin::form.hero_style_editorial'),
            self::Immersive => (string) __('capell-admin::form.hero_style_immersive'),
            self::Compact => (string) __('capell-admin::form.hero_style_compact'),
        };
    }
}
