<?php

declare(strict_types=1);

use Capell\Core\ThemeStudio\Actions\ResolveThemeRuntimeAction;
use Capell\Core\ThemeStudio\Assets\ThemeTokenStore;
use Capell\Core\ThemeStudio\Data\BrandProfileData;
use Capell\Core\ThemeStudio\Data\ThemeDefinitionData;
use Capell\Core\ThemeStudio\Data\ThemePresetData;
use Capell\Core\ThemeStudio\Exceptions\ThemeNotFoundException;
use Capell\Core\ThemeStudio\Theme\ThemeRegistry;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

it('registers, returns, and replaces theme metadata', function (): void {
    $registry = new ThemeRegistry;
    $original = themeRegistryDefinition('metadata-theme');
    $replacement = themeRegistryDefinition('metadata-theme', extends: 'foundation');

    $registry->register($original);

    expect($registry->has('metadata-theme'))->toBeTrue()
        ->and($registry->definition('metadata-theme'))->toBe($original)
        ->and($registry->definitions())->toBe(['metadata-theme' => $original]);

    $registry->register($replacement);

    expect($registry->definition('metadata-theme'))->toBe($replacement)
        ->and(fn (): ThemeDefinitionData => $registry->definition('missing-theme'))
        ->toThrow(ThemeNotFoundException::class);
});

it('sorts registered definitions by key', function (): void {
    $registry = new ThemeRegistry;
    $registry->register(themeRegistryDefinition('zulu'));
    $registry->register(themeRegistryDefinition('alpha'));

    expect(array_keys($registry->definitions()))->toBe(['alpha', 'zulu']);
});

it('preserves boot registrations across an Octane operation boundary', function (): void {
    $registry = resolve(ThemeRegistry::class);
    $definition = themeRegistryDefinition('worker-theme');
    $registry->register($definition);

    app()->forgetScopedInstances();

    expect(resolve(ThemeRegistry::class))->toBe($registry)
        ->and(resolve(ThemeRegistry::class)->definition('worker-theme'))->toBe($definition);
});

it('resolves runtime metadata and writes token css', function (): void {
    $directory = storage_path('framework/testing/theme-tokens-' . Str::uuid()->toString());

    app()->instance(ThemeTokenStore::class, new ThemeTokenStore($directory));
    resolve(ThemeRegistry::class)->register(themeRegistryDefinition('runtime-theme'));

    try {
        $runtime = ResolveThemeRuntimeAction::run(
            activeTheme: 'runtime-theme',
            activePreset: 'default',
            brand: new BrandProfileData,
        );

        expect($runtime->themeKey)->toBe('runtime-theme')
            ->and($runtime->tokenCssPath)->not->toBeNull()
            ->and(File::exists((string) $runtime->tokenCssPath))->toBeTrue();
    } finally {
        File::deleteDirectory($directory);
    }
});

function themeRegistryDefinition(string $themeKey, ?string $extends = null): ThemeDefinitionData
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
        extends: $extends,
    );
}
