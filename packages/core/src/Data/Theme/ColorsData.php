<?php

declare(strict_types=1);

namespace Capell\Core\Data\Theme;

use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapName(SnakeCaseMapper::class)]
class ColorsData extends Data
{
    /**
     * @param  array<int, ColorData>  $palette
     */
    public function __construct(
        public ?string $linkColor = null,
        public ?string $linkColorActive = null,
        public ?string $dividerColor = null,
        public ?string $darkModeToggle = null,
        #[MapInputName('colors')]
        public array $palette = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toLegacyMeta(): array
    {
        return array_filter([
            'link_color' => $this->linkColor,
            'link_color_active' => $this->linkColorActive,
            'divider_color' => $this->dividerColor,
            'dark_mode_toggle' => $this->darkModeToggle,
            'colors' => array_map(fn (ColorData $color): array => $color->toArray(), $this->palette),
        ], static fn (string|array|null $value): bool => $value !== null);
    }
}
