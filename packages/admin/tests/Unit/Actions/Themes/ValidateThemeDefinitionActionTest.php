<?php

declare(strict_types=1);

use Capell\Admin\Actions\Themes\ValidateThemeDefinitionAction;
use Capell\Core\Models\Theme;
use Capell\Core\ThemeStudio\Data\ThemeDefinitionData;
use Capell\Core\ThemeStudio\Data\ThemeFrontendBuildAssetsData;
use Capell\Core\ThemeStudio\Data\ThemePresetData;
use Capell\Core\ThemeStudio\Theme\ThemeRegistry;
use Illuminate\Filesystem\Filesystem;

it('reports missing registered theme definitions', function (): void {
    app()->instance(ThemeRegistry::class, new ThemeRegistry);

    $diagnostics = ValidateThemeDefinitionAction::run('missing-theme');

    expect($diagnostics->isValid())->toBeFalse()
        ->and($diagnostics->hasDefinition)->toBeFalse()
        ->and($diagnostics->errors)->toContain(__('capell-admin::theme-library.diagnostics.missing_definition'));
});

it('validates registered theme definitions with warnings for incomplete metadata', function (): void {
    $registry = new ThemeRegistry;
    $definition = new ThemeDefinitionData(
        key: 'minimal',
        name: 'Minimal',
        description: 'Minimal theme.',
        package: 'capell-app/theme-minimal',
        previewImage: '',
        tags: [],
        bestFit: [],
        presets: [
            new ThemePresetData(
                key: 'base',
                name: 'Base',
                description: 'Base',
                previewImage: '/themes/minimal-base.jpg',
                values: [],
            ),
        ],
        includedSections: ['navigation', 'hero'],
        assets: [],
    );

    $registry->register(
        definition: $definition,
    );
    app()->instance(ThemeRegistry::class, $registry);

    $theme = Theme::factory()->make(['key' => 'minimal']);
    $diagnostics = ValidateThemeDefinitionAction::run('minimal', $definition, $theme);

    expect($diagnostics->isValid())->toBeTrue()
        ->and($diagnostics->hasPreviewImage)->toBeFalse()
        ->and($diagnostics->missingAssets)->toBe(['frontend'])
        ->and($diagnostics->warnings)->not->toBeEmpty();
});

it('validates declared frontend build source and application input files', function (): void {
    $files = new Filesystem;
    $cssBuildInput = 'resources/css/capell/themes/validator-test.css';
    $absoluteCssBuildInput = base_path($cssBuildInput);
    $files->ensureDirectoryExists(dirname($absoluteCssBuildInput));
    $files->put($absoluteCssBuildInput, '@import "theme.css";');

    try {
        $definition = themeDefinitionWithFrontendBuildAssets(new ThemeFrontendBuildAssetsData(
            cssSource: 'src/Data.php',
            cssBuildInput: $cssBuildInput,
            condition: 'theme-css:validator-test',
        ));

        $diagnostics = ValidateThemeDefinitionAction::run('validator-test', $definition, Theme::factory()->make());

        expect($diagnostics->missingAssets)->toBe([]);
    } finally {
        $files->delete($absoluteCssBuildInput);
    }
});

it('keeps legacy public asset URLs valid without build provenance', function (): void {
    $definition = themeDefinitionWithFrontendBuildAssets(new ThemeFrontendBuildAssetsData(
        cssSource: 'resources/css/missing.css',
        cssBuildInput: 'resources/css/capell/themes/missing.css',
        condition: 'theme-css:validator-test',
    ));
    $definition->frontend = [];
    $definition->assets = ['css' => 'vendor/example/theme.css'];

    $diagnostics = ValidateThemeDefinitionAction::run('validator-test', $definition, Theme::factory()->make());

    expect($diagnostics->missingAssets)->toBe([]);
});

it('reports missing or unsafe frontend build files', function (ThemeFrontendBuildAssetsData $buildAssets, array $missingAssets): void {
    $definition = themeDefinitionWithFrontendBuildAssets($buildAssets);
    $diagnostics = ValidateThemeDefinitionAction::run('validator-test', $definition, Theme::factory()->make());

    expect($diagnostics->missingAssets)->toBe($missingAssets);
})->with([
    'missing package source' => [
        new ThemeFrontendBuildAssetsData(
            cssSource: 'resources/css/missing.css',
            cssBuildInput: 'resources/css/capell/themes/missing.css',
            condition: 'theme-css:validator-test',
        ),
        ['frontend source', 'frontend build input'],
    ],
    'unsafe absolute paths' => [
        new ThemeFrontendBuildAssetsData(
            cssSource: '/tmp/theme.css',
            cssBuildInput: '/tmp/theme.css',
            condition: 'theme-css:validator-test',
        ),
        ['frontend source', 'frontend build input'],
    ],
    'unsafe traversal paths' => [
        new ThemeFrontendBuildAssetsData(
            cssSource: '../theme.css',
            cssBuildInput: '../theme.css',
            condition: 'theme-css:validator-test',
        ),
        ['frontend source', 'frontend build input'],
    ],
    'condition for another theme' => [
        new ThemeFrontendBuildAssetsData(
            cssSource: 'src/Data.php',
            cssBuildInput: 'resources/css/capell/themes/missing.css',
            condition: 'theme-css:another-theme',
        ),
        ['frontend build input', 'frontend condition'],
    ],
]);

function themeDefinitionWithFrontendBuildAssets(ThemeFrontendBuildAssetsData $buildAssets): ThemeDefinitionData
{
    return new ThemeDefinitionData(
        key: 'validator-test',
        name: 'Validator test',
        description: 'Theme asset validator fixture.',
        package: 'spatie/laravel-data',
        previewImage: '/themes/validator-test.jpg',
        tags: [],
        bestFit: [],
        presets: [
            new ThemePresetData(
                key: 'base',
                name: 'Base',
                description: 'Base',
                previewImage: '/themes/validator-test.jpg',
                values: [],
            ),
        ],
        frontend: ['assets' => $buildAssets],
    );
}
