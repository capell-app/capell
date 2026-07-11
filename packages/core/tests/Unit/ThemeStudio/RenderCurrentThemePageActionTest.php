<?php

declare(strict_types=1);

use Capell\Core\ThemeStudio\Actions\RenderCurrentThemePageAction;
use Capell\Core\ThemeStudio\Contracts\ThemePageAdapter;
use Capell\Core\ThemeStudio\Contracts\ThemeRenderer;
use Capell\Core\ThemeStudio\Contracts\ThemeRuntimeSettings;
use Capell\Core\ThemeStudio\Data\BrandProfileData;
use Capell\Core\ThemeStudio\Data\HeroSectionData;
use Capell\Core\ThemeStudio\Data\ThemeDefinitionData;
use Capell\Core\ThemeStudio\Data\ThemePageData;
use Capell\Core\ThemeStudio\Data\ThemePresetData;
use Capell\Core\ThemeStudio\Theme\ThemePageAdapterRegistry;
use Capell\Core\ThemeStudio\Theme\ThemeRegistry;

it('uses the active theme page adapter without replacing the global fallback adapter', function (): void {
    $registry = new ThemeRegistry;
    $registry->register(
        definition: renderCurrentThemeDefinition('default'),
        themeRenderer: renderCurrentThemeRenderer('default'),
        sectionRenderers: [],
    );
    $registry->register(
        definition: renderCurrentThemeDefinition('saas'),
        themeRenderer: renderCurrentThemeRenderer('saas'),
        sectionRenderers: [],
    );
    app()->instance(ThemeRegistry::class, $registry);

    app()->instance(ThemeRuntimeSettings::class, new class implements ThemeRuntimeSettings
    {
        public function activeTheme(): string
        {
            return 'default';
        }

        public function activePreset(): string
        {
            return 'base';
        }

        public function brandProfile(): BrandProfileData
        {
            return new BrandProfileData;
        }

        public function themeOverrides(): array
        {
            return [];
        }
    });

    app()->instance(ThemePageAdapter::class, new class implements ThemePageAdapter
    {
        public function currentPage(): ThemePageData
        {
            return renderCurrentThemePage('Fallback page');
        }
    });

    $adapterRegistry = new ThemePageAdapterRegistry(app());
    $adapterRegistry->register('saas', new class implements ThemePageAdapter
    {
        public function currentPage(): ThemePageData
        {
            return renderCurrentThemePage('SaaS page');
        }
    });
    app()->instance(ThemePageAdapterRegistry::class, $adapterRegistry);

    expect(RenderCurrentThemePageAction::run(activeTheme: 'default'))->toBe('default:Fallback page')
        ->and(RenderCurrentThemePageAction::run(activeTheme: 'saas'))->toBe('saas:SaaS page');
});

it('uses the selected preset when rendering current theme output', function (): void {
    $registry = new ThemeRegistry;
    $registry->register(
        definition: renderCurrentThemeDefinitionWithPresets('default'),
        themeRenderer: renderCurrentThemeBrandRenderer('default'),
        sectionRenderers: [],
    );
    app()->instance(ThemeRegistry::class, $registry);

    app()->instance(ThemeRuntimeSettings::class, new class implements ThemeRuntimeSettings
    {
        public function activeTheme(): string
        {
            return 'default';
        }

        public function activePreset(): string
        {
            return 'base';
        }

        public function brandProfile(): BrandProfileData
        {
            return new BrandProfileData;
        }

        public function themeOverrides(): array
        {
            return [];
        }
    });

    app()->instance(ThemePageAdapter::class, new class implements ThemePageAdapter
    {
        public function currentPage(): ThemePageData
        {
            return renderCurrentThemePage('Preset page');
        }
    });
    app()->instance(ThemePageAdapterRegistry::class, new ThemePageAdapterRegistry(app()));

    expect(RenderCurrentThemePageAction::run(activeTheme: 'default', activePreset: 'editorial'))
        ->toBe('default:Preset page:#0f766e')
        ->and(RenderCurrentThemePageAction::run(activeTheme: 'default', activePreset: 'base'))
        ->toBe('default:Preset page:#1a2d6d');
});

function renderCurrentThemeDefinition(string $themeKey): ThemeDefinitionData
{
    return new ThemeDefinitionData(
        key: $themeKey,
        name: $themeKey,
        description: $themeKey,
        package: 'capell-app/foundation-theme',
        previewImage: '/preview.jpg',
        tags: [],
        bestFit: [],
        presets: [
            new ThemePresetData(
                key: 'base',
                name: 'Base',
                description: 'Base',
                previewImage: '/preview.jpg',
                values: [],
            ),
        ],
        includedSections: [],
    );
}

function renderCurrentThemeDefinitionWithPresets(string $themeKey): ThemeDefinitionData
{
    return new ThemeDefinitionData(
        key: $themeKey,
        name: $themeKey,
        description: $themeKey,
        package: 'capell-app/foundation-theme',
        previewImage: '/preview.jpg',
        tags: [],
        bestFit: [],
        presets: [
            new ThemePresetData(
                key: 'base',
                name: 'Base',
                description: 'Base',
                previewImage: '/preview.jpg',
                values: ['primaryColor' => '#1a2d6d'],
            ),
            new ThemePresetData(
                key: 'editorial',
                name: 'Editorial',
                description: 'Editorial',
                previewImage: '/preview-editorial.jpg',
                values: ['primaryColor' => '#0f766e'],
            ),
        ],
        includedSections: [],
    );
}

function renderCurrentThemeRenderer(string $themeKey): ThemeRenderer
{
    return new readonly class($themeKey) implements ThemeRenderer
    {
        public function __construct(private string $themeKey) {}

        public function themeKey(): string
        {
            return $this->themeKey;
        }

        public function render(ThemePageData $page): string
        {
            return $this->themeKey . ':' . $page->title;
        }
    };
}

function renderCurrentThemeBrandRenderer(string $themeKey): ThemeRenderer
{
    return new readonly class($themeKey) implements ThemeRenderer
    {
        public function __construct(private string $themeKey) {}

        public function themeKey(): string
        {
            return $this->themeKey;
        }

        public function render(ThemePageData $page): string
        {
            return $this->themeKey . ':' . $page->title . ':' . $page->brand->primaryColor;
        }
    };
}

function renderCurrentThemePage(string $title): ThemePageData
{
    return new ThemePageData(
        title: $title,
        brand: new BrandProfileData,
        sections: [
            new HeroSectionData(heading: $title),
        ],
    );
}
