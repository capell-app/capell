<?php

declare(strict_types=1);

namespace Capell\Core\Enums;

use Filament\Support\Contracts\HasLabel;

enum MediaAlignment: string implements HasLabel
{
    case Top = 'top';
    case Bottom = 'bottom';
    case Left = 'left';
    case Right = 'right';

    public function getLabel(): string
    {
        return match ($this) {
            self::Top => __('capell::media.alignment.top'),
            self::Bottom => __('capell::media.alignment.bottom'),
            self::Left => __('capell::media.alignment.left'),
            self::Right => __('capell::media.alignment.right'),
        };
    }
}
