<?php

declare(strict_types=1);

use Capell\Core\Models\Blueprint;
use Capell\Core\ThemeStudio\Data\ThemeDefinitionData;
use Capell\Core\ThemeStudio\Data\ThemePresetData;
use Capell\Core\ThemeStudio\Theme\ThemeRegistry;
use Illuminate\Support\Facades\Artisan;

beforeEach(function (): void {
    Blueprint::factory()->theme()->default()->create();
});

it('validates registered theme definitions from the console', function (): void {
    $registry = new ThemeRegistry;
    $registry->register(
        definition: validateThemesCommandDefinition('console-theme'),
    );
    app()->instance(ThemeRegistry::class, $registry);

    $exitCode = Artisan::call('capell:themes:validate', ['themeKey' => 'console-theme']);

    expect($exitCode)->toBe(0)
        ->and(Artisan::output())->toContain('[OK] Console Theme (console-theme)');
});

it('fails validation when no theme matches the filter', function (): void {
    app()->instance(ThemeRegistry::class, new ThemeRegistry);

    $exitCode = Artisan::call('capell:themes:validate', ['themeKey' => 'missing-theme']);

    expect($exitCode)->toBe(1)
        ->and(Artisan::output())->toContain('No theme definitions matched.');
});

function validateThemesCommandDefinition(string $key): ThemeDefinitionData
{
    return new ThemeDefinitionData(
        key: $key,
        name: 'Console Theme',
        description: 'Console theme description.',
        package: 'capell-app/' . $key,
        previewImage: '/themes/console.jpg',
        tags: [],
        bestFit: [],
        presets: [
            new ThemePresetData(
                key: 'default',
                name: 'Default',
                description: 'Default preset.',
                previewImage: '/themes/console.jpg',
                values: [],
            ),
        ],
        includedSections: ['navigation', 'hero', 'features', 'footer'],
        assets: ['frontend' => '/themes/console.css'],
    );
}
