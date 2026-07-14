<?php

declare(strict_types=1);

namespace Capell\Core\Enums;

use Filament\Support\Contracts\HasLabel;

enum InteractionTargetType: string implements HasLabel
{
    case Widget = 'widget';
    case Fragment = 'fragment';
    case Url = 'url';
    case PublicAction = 'public_action';

    public function getLabel(): string
    {
        return match ($this) {
            self::Widget => __('capell::generic.widget'),
            self::Fragment => __('capell::generic.fragment'),
            self::Url => __('capell::generic.url'),
            self::PublicAction => __('capell::generic.public_action'),
        };
    }
}
