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
            self::Top => 'Top (full width)',
            self::Bottom => 'Bottom (full width)',
            self::Left => 'Left (one third)',
            self::Right => 'Right (one third)',
        };
    }
}
