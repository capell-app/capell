<?php

declare(strict_types=1);

use Capell\Admin\Actions\Themes\ValidateThemeDefinitionAction;
use Capell\Core\Models\Theme;
use Capell\Core\ThemeStudio\Data\ThemeDefinitionData;
use Capell\Core\ThemeStudio\Data\ThemePresetData;
use Capell\Core\ThemeStudio\Theme\ThemeRegistry;

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
