<?php

declare(strict_types=1);

namespace Capell\Admin\Enums;

use Filament\Support\Colors\Color;
use Filament\Support\Contracts\HasColor as ColorInterface;

enum FilamentColorEnum: string implements ColorInterface
{
    case Danger = 'danger';

    case Gray = 'gray';

    case Info = 'info';

    case LightGray = 'light-gray';

    case Success = 'success';

    case Warning = 'warning';

    /**
     * @return array<string, array<int, string>>
     */
    public static function colors(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $color): array => [$color->value => $color->getColor()])
            ->all();
    }

    public function getColor(): array
    {
        return match ($this) {
            self::Danger => Color::Red,
            self::Gray => Color::Gray,
            self::LightGray => [
                50 => 'oklch(0.98 0.005 264)',
                100 => 'oklch(0.95 0.01 264)',
                200 => 'oklch(0.90 0.012 264)',
                300 => 'oklch(0.85 0.015 264)',
                400 => 'oklch(0.80 0.018 264)',
                500 => 'oklch(0.75 0.02 264)',
                600 => 'oklch(0.70 0.022 264)',
                700 => 'oklch(0.65 0.025 264)',
                800 => 'oklch(0.60 0.028 264)',
                900 => 'oklch(0.55 0.03 264)',
            ],
            self::Info => Color::Blue,
            self::Success => Color::Green,
            self::Warning => Color::Orange,
        };
    }
}
