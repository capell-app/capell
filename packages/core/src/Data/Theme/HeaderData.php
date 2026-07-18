<?php

declare(strict_types=1);

namespace Capell\Core\Data\Theme;

use Capell\Core\Enums\HeaderPositionEnum;
use Capell\Core\Enums\MenuAlignmentEnum;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapName(SnakeCaseMapper::class)]
final class HeaderData extends Data
{
    public function __construct(
        public bool $enabled = true,
        public ChromeColorsData $light = new ChromeColorsData,
        public ?ChromeColorsData $dark = null,
        public ?string $file = null,
        public ?string $height = null,
        public ?HeaderPositionEnum $position = null,
        public ?MenuAlignmentEnum $menuAlignment = null,
    ) {}

    /**
     * @param  array<string, mixed>  $meta
     */
    public static function fromLegacyMeta(array $meta): self
    {
        $light = new ChromeColorsData(
            backgroundColor: $meta['header_background_color'] ?? null,
            borderColor: $meta['header_border_color'] ?? null,
            color: $meta['header_color'] ?? null,
        );

        $darkCandidate = new ChromeColorsData(
            backgroundColor: $meta['header_dark_background_color'] ?? null,
            borderColor: $meta['header_dark_border_color'] ?? null,
            color: $meta['header_dark_color'] ?? null,
        );

        return new self(
            enabled: (bool) ($meta['header'] ?? true),
            light: $light,
            dark: $darkCandidate->isEmpty() ? null : $darkCandidate,
            file: $meta['header_file'] ?? null,
            height: isset($meta['header_height']) ? (string) $meta['header_height'] : null,
            position: isset($meta['header_position']) && $meta['header_position'] !== '' ? HeaderPositionEnum::from($meta['header_position']) : null,
            menuAlignment: isset($meta['header_menu_alignment']) && $meta['header_menu_alignment'] !== '' ? MenuAlignmentEnum::from($meta['header_menu_alignment']) : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toLegacyMeta(): array
    {
        $out = [
            'header' => $this->enabled,
            'header_background_color' => $this->light->backgroundColor,
            'header_border_color' => $this->light->borderColor,
            'header_color' => $this->light->color,
            'header_file' => $this->file,
            'header_height' => $this->height,
            'header_position' => $this->position?->value,
            'header_menu_alignment' => $this->menuAlignment?->value,
        ];

        if ($this->dark instanceof ChromeColorsData) {
            $out['header_dark_background_color'] = $this->dark->backgroundColor;
            $out['header_dark_border_color'] = $this->dark->borderColor;
            $out['header_dark_color'] = $this->dark->color;
        }

        return $out;
    }
}
