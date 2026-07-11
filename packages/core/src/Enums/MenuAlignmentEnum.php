<?php

declare(strict_types=1);

namespace Capell\Core\Enums;

use Filament\Support\Contracts\HasLabel;

enum MenuAlignmentEnum: string implements HasLabel
{
    case Left = 'left';

    case Center = 'center';

    case Right = 'right';

    public function getLabel(): string
    {
        return match ($this) {
            self::Left => __('capell-admin::generic.left'),
            self::Center => __('capell-admin::generic.center'),
            self::Right => __('capell-admin::generic.right'),
        };
    }
}
