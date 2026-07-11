<?php

declare(strict_types=1);

namespace Capell\Admin\Data\Themes;

use Capell\Core\Models\Theme;
use Capell\Core\ThemeStudio\Data\ThemeDefinitionData;
use Spatie\LaravelData\Data;

final class ThemeEditorStateData extends Data
{
    /**
     * @param  array<string, mixed>  $preset
     * @param  array<string, mixed>  $brand
     * @param  array<string, mixed>  $header
     * @param  array<string, mixed>  $surface
     * @param  array<string, mixed>  $footer
     * @param  array<string, mixed>  $assets
     * @param  array<string, mixed>  $advanced
     * @param  array<string, mixed>  $admin
     */
    public function __construct(
        public array $preset,
        public array $brand,
        public array $header,
        public array $surface,
        public array $footer,
        public array $assets,
        public array $advanced,
        public array $admin,
    ) {}

    public static function defaults(?ThemeDefinitionData $definition = null): self
    {
        $activePreset = $definition?->presets[0]->key ?? 'default';

        return new self(
            preset: [
                'active' => $activePreset,
            ],
            brand: [
                'primaryColor' => '#0f766e',
                'accentColor' => '#f59e0b',
                'neutralColor' => '#111827',
                'headingFont' => 'inter',
                'bodyFont' => 'inter',
                'radius' => 'md',
            ],
            header: [
                'enabled' => true,
                'component' => null,
                'position' => 'sticky',
                'overHero' => false,
            ],
            surface: [
                'surfaceColor' => '#ffffff',
                'foregroundColor' => '#111827',
                'container' => 'lg',
                'headingScale' => 'balanced',
                'cardDensity' => 'comfortable',
            ],
            footer: [
                'enabled' => true,
                'component' => null,
                'copy' => null,
            ],
            assets: [
                'paths' => array_values($definition->assets ?? []),
                'buildPath' => null,
            ],
            advanced: [
                'customCss' => '',
                'metaTags' => '',
                'mainClass' => '',
                'roundedImages' => false,
            ],
            admin: [
                'description' => $definition?->description,
                'icon' => 'heroicon-o-swatch',
                'image' => null,
                'preview' => [
                    'device' => 'desktop',
                    'colorMode' => 'light',
                ],
            ],
        );
    }

    public static function forTheme(Theme $theme, ?ThemeDefinitionData $definition = null): self
    {
        $defaults = self::defaults($definition);

        return new self(
            preset: self::mergeSection($defaults->preset, data_get($theme->meta, 'editor.preset')),
            brand: self::mergeSection($defaults->brand, data_get($theme->meta, 'editor.brand')),
            header: self::mergeSection($defaults->header, data_get($theme->meta, 'editor.header')),
            surface: self::mergeSection($defaults->surface, data_get($theme->meta, 'editor.surface')),
            footer: self::mergeSection($defaults->footer, data_get($theme->meta, 'editor.footer')),
            assets: self::mergeSection($defaults->assets, data_get($theme->meta, 'editor.assets')),
            advanced: self::mergeSection($defaults->advanced, data_get($theme->meta, 'editor.advanced')),
            admin: self::mergeSection($defaults->admin, data_get($theme->admin, 'editor')),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function metaEditor(): array
    {
        return [
            'preset' => $this->preset,
            'brand' => $this->brand,
            'header' => $this->header,
            'surface' => $this->surface,
            'footer' => $this->footer,
            'assets' => $this->assets,
            'advanced' => $this->advanced,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function adminEditor(): array
    {
        return $this->admin;
    }

    /**
     * @param  array<string, mixed>  $defaults
     * @return array<string, mixed>
     */
    private static function mergeSection(array $defaults, mixed $state): array
    {
        return [
            ...$defaults,
            ...(is_array($state) ? $state : []),
        ];
    }
}
