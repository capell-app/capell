<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Themes;

use Capell\Core\Enums\FrontendRuntime;
use Capell\Core\ThemeStudio\Data\ThemeDefinitionData;
use Capell\Core\ThemeStudio\Data\ThemePresetData;
use Capell\Core\ThemeStudio\Rendering\BladeThemeRenderer;
use Capell\Core\ThemeStudio\Rendering\ViewSectionRenderer;
use Capell\Core\ThemeStudio\Theme\ThemeRegistry;

final class DefaultTheme
{
    public const string KEY = 'default';

    public static function definition(): ThemeDefinitionData
    {
        return new ThemeDefinitionData(
            key: self::KEY,
            name: 'Default',
            description: 'Minimal built-in Capell theme for fresh frontend installs.',
            package: 'capell-app/frontend',
            previewImage: '',
            tags: ['Default', 'Minimal', 'Built-in'],
            bestFit: ['Fresh installs', 'Content sites', 'Starter sites'],
            presets: [
                new ThemePresetData(
                    key: 'default',
                    name: 'Default',
                    description: 'Plain, accessible defaults for static public pages.',
                    previewImage: '',
                    values: [
                        'primaryColor' => '#087765',
                        'accentColor' => '#0e91b2',
                        'neutralColor' => '#101715',
                        'surfaceColor' => '#f5f7f4',
                        'foregroundColor' => '#101715',
                        'headingFont' => 'sora',
                        'bodyFont' => 'inter',
                        'spacing' => 'balanced',
                        'cardStyle' => 'subtle',
                        'layoutPresentation' => 'structured',
                        'motionIntensity' => 'none',
                        'mediaTreatment' => 'natural',
                        'radius' => 'sm',
                        'headingScale' => 'balanced',
                        'cardDensity' => 'comfortable',
                    ],
                ),
            ],
            includedSections: ['navigation', 'hero', 'features', 'content-listing', 'proof', 'cta', 'footer'],
            assets: ['css' => 'vendor/capell-frontend/capell-frontend.css'],
            runtime: FrontendRuntime::Blade,
            frontend: [
                'runtime' => [
                    'uses_alpine' => false,
                    'uses_frontend_chrome' => false,
                ],
            ],
        );
    }

    public static function register(ThemeRegistry $registry): void
    {
        $sectionRenderers = self::sectionRenderers();

        $registry->register(
            definition: self::definition(),
            themeRenderer: new BladeThemeRenderer(
                themeKey: self::KEY,
                layoutView: 'capell::themes.default.page',
                sectionRenderers: $sectionRenderers,
            ),
            sectionRenderers: array_values($sectionRenderers),
        );
    }

    /**
     * @return array<string, ViewSectionRenderer>
     */
    private static function sectionRenderers(): array
    {
        return [
            'navigation' => new ViewSectionRenderer(self::KEY, 'navigation', 'capell::themes.default.sections.navigation', failLoudly: true),
            'hero' => new ViewSectionRenderer(self::KEY, 'hero', 'capell::themes.default.sections.hero', failLoudly: true),
            'features' => new ViewSectionRenderer(self::KEY, 'features', 'capell::themes.default.sections.features', failLoudly: true),
            'content-listing' => new ViewSectionRenderer(self::KEY, 'content-listing', 'capell::themes.default.sections.content-listing', failLoudly: true),
            'proof' => new ViewSectionRenderer(self::KEY, 'proof', 'capell::themes.default.sections.proof', failLoudly: true),
            'cta' => new ViewSectionRenderer(self::KEY, 'cta', 'capell::themes.default.sections.cta', failLoudly: true),
            'footer' => new ViewSectionRenderer(self::KEY, 'footer', 'capell::themes.default.sections.footer', failLoudly: true),
        ];
    }
}
