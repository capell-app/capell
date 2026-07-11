<?php

declare(strict_types=1);

namespace Capell\Core\Data\Theme;

use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapName(SnakeCaseMapper::class)]
class FooterData extends Data
{
    public function __construct(
        public bool $enabled = true,
        public ChromeColorsData $light = new ChromeColorsData,
        public ?ChromeColorsData $dark = null,
        public ?string $file = null,
    ) {}

    /**
     * @param  array<string, mixed>  $meta
     */
    public static function fromLegacyMeta(array $meta): self
    {
        $light = new ChromeColorsData(
            backgroundColor: $meta['footer_background_color'] ?? null,
            borderColor: $meta['footer_border_color'] ?? null,
            color: $meta['footer_color'] ?? null,
        );

        $darkCandidate = new ChromeColorsData(
            backgroundColor: $meta['footer_dark_background_color'] ?? null,
            borderColor: $meta['footer_dark_border_color'] ?? null,
            color: $meta['footer_dark_color'] ?? null,
        );

        return new self(
            enabled: (bool) ($meta['footer'] ?? true),
            light: $light,
            dark: $darkCandidate->isEmpty() ? null : $darkCandidate,
            file: $meta['footer_file'] ?? null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toLegacyMeta(): array
    {
        $out = [
            'footer' => $this->enabled,
            'footer_background_color' => $this->light->backgroundColor,
            'footer_border_color' => $this->light->borderColor,
            'footer_color' => $this->light->color,
            'footer_file' => $this->file,
        ];

        if ($this->dark instanceof ChromeColorsData) {
            $out['footer_dark_background_color'] = $this->dark->backgroundColor;
            $out['footer_dark_border_color'] = $this->dark->borderColor;
            $out['footer_dark_color'] = $this->dark->color;
        }

        return $out;
    }
}
