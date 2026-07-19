<?php

declare(strict_types=1);

namespace Capell\Core\Enums;

use Filament\Support\Contracts\HasLabel;

enum DefaultColorEnum: string implements HasLabel
{
    case Black = 'black';

    case Danger = 'danger';

    case DarkGray = 'dark-gray';

    case Gray = 'gray';

    case Info = 'info';

    case LightGray = 'light-gray';

    case Primary = 'primary';

    case Secondary = 'secondary';

    case Success = 'success';

    case Warning = 'warning';

    case White = 'white';

    /**
     * @return list<array{name: string, color: string}>
     */
    public static function getValues(): array
    {
        return array_values(collect(self::cases())
            ->map(fn (self $color): array => ['name' => $color->value, 'color' => $color->getColor()])
            ->all());
    }

    /**
     * @return array<string, string>
     */
    public static function getKeyValues(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $color): array => [$color->value => $color->getColor()])
            ->all();
    }

    public function getColor(): string
    {
        $colors = config('capell.default_colors', []);

        return $colors[str_replace('-', '_', $this->value)] ?? '';
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::Black => __('capell-core::generic.black'),
            self::Danger => __('capell-core::generic.danger'),
            self::DarkGray => __('capell-core::generic.dark_gray'),
            self::Gray => __('capell-core::generic.gray'),
            self::Info => __('capell-core::generic.info'),
            self::LightGray => __('capell-core::generic.light_gray'),
            self::Primary => __('capell-core::generic.primary'),
            self::Secondary => __('capell-core::generic.secondary'),
            self::Success => __('capell-core::generic.success'),
            self::Warning => __('capell-core::generic.warning'),
            self::White => __('capell-core::generic.white'),
        };
    }
}
