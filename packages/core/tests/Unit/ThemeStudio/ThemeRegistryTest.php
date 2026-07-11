<?php

declare(strict_types=1);

use Capell\Core\ThemeStudio\Actions\ResolveThemeRuntimeAction;
use Capell\Core\ThemeStudio\Assets\ThemeTokenStore;
use Capell\Core\ThemeStudio\Contracts\ThemeRenderer;
use Capell\Core\ThemeStudio\Data\BrandProfileData;
use Capell\Core\ThemeStudio\Data\ThemeDefinitionData;
use Capell\Core\ThemeStudio\Data\ThemePageData;
use Capell\Core\ThemeStudio\Data\ThemePresetData;
use Capell\Core\ThemeStudio\Exceptions\ThemeNotFoundException;
use Capell\Core\ThemeStudio\Theme\ThemeRegistry;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

it('registers a theme definition without a renderer', function (): void {
    $registry = new ThemeRegistry;

    $registry->register(themeRegistryDefinition('definition-only-theme'));

    expect($registry->has('definition-only-theme'))->toBeTrue()
        ->and($registry->hasRenderer('definition-only-theme'))->toBeFalse();
});

it('throws when calling renderer() for a definition-only theme', function (): void {
    $registry = new ThemeRegistry;

    $registry->register(themeRegistryDefinition('definition-only-theme'));

    expect(fn (): ThemeRenderer => $registry->renderer('definition-only-theme'))
        ->toThrow(ThemeNotFoundException::class);
});

it('reports hasRenderer() true for themes registered with a renderer', function (): void {
    $registry = new ThemeRegistry;

    $registry->register(
        definition: themeRegistryDefinition('rendered-theme'),
        themeRenderer: themeRegistryRenderer('rendered-theme'),
        sectionRenderers: [],
    );

    expect($registry->has('rendered-theme'))->toBeTrue()
        ->and($registry->hasRenderer('rendered-theme'))->toBeTrue()
        ->and($registry->renderer('rendered-theme'))->toBeInstanceOf(ThemeRenderer::class);
});

it('clears a previously-registered renderer when re-registering without one', function (): void {
    $registry = new ThemeRegistry;

    $registry->register(
        definition: themeRegistryDefinition('migrated-theme'),
        themeRenderer: themeRegistryRenderer('migrated-theme'),
        sectionRenderers: [],
    );

    expect($registry->hasRenderer('migrated-theme'))->toBeTrue();

    $registry->register(themeRegistryDefinition('migrated-theme'));

    expect($registry->hasRenderer('migrated-theme'))->toBeFalse();

    expect(fn (): ThemeRenderer => $registry->renderer('migrated-theme'))
        ->toThrow(ThemeNotFoundException::class);
});

it('findRenderer() returns the renderer instance for a theme registered with one', function (): void {
    $registry = new ThemeRegistry;

    $registry->register(
        definition: themeRegistryDefinition('findable-rendered-theme'),
        themeRenderer: themeRegistryRenderer('findable-rendered-theme'),
        sectionRenderers: [],
    );

    expect($registry->findRenderer('findable-rendered-theme'))->toBeInstanceOf(ThemeRenderer::class);
});

it('findRenderer() returns null for a definition-only or unregistered theme', function (): void {
    $registry = new ThemeRegistry;

    $registry->register(themeRegistryDefinition('findable-definition-only-theme'));

    expect($registry->findRenderer('findable-definition-only-theme'))->toBeNull()
        ->and($registry->findRenderer('never-registered-theme'))->toBeNull();
});

it('still resolves runtime data and writes token css for a definition-only theme', function (): void {
    $directory = storage_path('framework/testing/theme-tokens-' . Str::uuid()->toString());

    app()->instance(ThemeTokenStore::class, new ThemeTokenStore($directory));

    resolve(ThemeRegistry::class)->register(
        themeRegistryDefinition('definition-only-runtime-theme'),
    );

    try {
        $runtime = ResolveThemeRuntimeAction::run(
            activeTheme: 'definition-only-runtime-theme',
            activePreset: 'default',
            brand: new BrandProfileData,
        );

        expect($runtime->renderer)->toBeNull()
            ->and($runtime->tokenCssPath)->not->toBeNull()
            ->and(File::exists((string) $runtime->tokenCssPath))->toBeTrue();
    } finally {
        File::deleteDirectory($directory);
    }
});

function themeRegistryDefinition(string $themeKey): ThemeDefinitionData
{
    return new ThemeDefinitionData(
        key: $themeKey,
        name: $themeKey,
        description: $themeKey,
        package: 'capell-app/theme-' . $themeKey,
        previewImage: '/preview.jpg',
        tags: [],
        bestFit: [],
        presets: [
            new ThemePresetData(
                key: 'default',
                name: 'Default',
                description: 'Default preset.',
                previewImage: '/preset.jpg',
            ),
        ],
    );
}

function themeRegistryRenderer(string $themeKey): ThemeRenderer
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
            return '';
        }
    };
}
