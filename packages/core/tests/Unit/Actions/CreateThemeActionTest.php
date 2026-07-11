<?php

declare(strict_types=1);

use Capell\Core\Actions\CreateThemeAction;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Theme;
use Capell\Core\ThemeStudio\Data\ThemeDefinitionData;
use Capell\Core\ThemeStudio\Data\ThemePresetData;
use Capell\Core\ThemeStudio\Theme\ThemeRegistry;
use Illuminate\Database\QueryException;

it('creates a theme with resolved theme type assets and configured default colors', function (): void {
    $type = Blueprint::factory()->theme()->default()->create();
    config(['capell.default_colors' => [
        'primary' => '#123456',
        'secondary' => '#654321',
    ]]);

    $theme = CreateThemeAction::run(
        key: 'brand',
        name: 'Brand',
        assets: ['resources/js/theme.js'],
        assetsPath: 'build/theme',
        defaultColors: true,
    );
    $colors = $theme->meta['colors'] ?? [];

    assert(is_array($colors));

    expect($theme)->toBeInstanceOf(Theme::class)
        ->and($theme->key)->toBe('brand')
        ->and($theme->name)->toBe('Brand')
        ->and($theme->blueprint_id)->toBe($type->id)
        ->and($theme->default)->toBeTrue()
        ->and($theme->meta)->toMatchArray([
            'footer' => true,
            'header' => true,
            'assets' => ['resources/js/theme.js'],
            'assets_path' => 'build/theme',
        ])
        ->and($colors)->toHaveKey('primary', '#123456')
        ->and($colors)->toHaveKey('secondary', '#654321')
        ->and($colors)->toHaveKey('warning', '');
});

it('updates an existing theme while preserving previous assets when no new assets are supplied', function (): void {
    $type = Blueprint::factory()->theme()->default()->create();
    $theme = Theme::factory()->createOne([
        'key' => 'brand',
        'name' => 'Existing Brand',
        'blueprint_id' => $type->id,
        'meta' => [
            'assets' => ['resources/js/existing.js'],
            'assets_path' => 'existing-build',
            'custom' => 'value',
        ],
    ]);

    $updatedTheme = CreateThemeAction::run(key: 'brand', name: 'Updated Brand');

    expect($updatedTheme->is($theme))->toBeTrue()
        ->and($updatedTheme->name)->toBe('Updated Brand')
        ->and($updatedTheme->meta)->toMatchArray([
            'footer' => true,
            'header' => true,
            'assets' => ['resources/js/existing.js'],
            'assets_path' => 'existing-build',
            'custom' => 'value',
        ]);
});

it('stores an active preset when one is supplied during theme creation', function (): void {
    Blueprint::factory()->theme()->default()->create();

    $theme = CreateThemeAction::run(
        key: 'editorial',
        name: 'Editorial',
        activePreset: 'launch',
    );

    expect($theme->meta)->toMatchArray([
        'active_preset' => 'launch',
        'editor' => [
            'preset' => [
                'active' => 'launch',
            ],
            'assets' => [
                'paths' => [],
                'buildPath' => null,
            ],
            'header' => ['enabled' => true],
            'footer' => ['enabled' => true],
        ],
        'assets' => [],
    ]);
});

it('seeds clean editor asset state when creating a theme', function (): void {
    Blueprint::factory()->theme()->default()->create();

    $theme = CreateThemeAction::run(
        key: 'clean-editor',
        name: 'Clean Editor',
        assets: ['resources/css/theme.css'],
        assetsPath: 'theme-build',
        activePreset: 'launch',
    );

    expect($theme->meta)->toMatchArray([
        'editor' => [
            'preset' => [
                'active' => 'launch',
            ],
            'assets' => [
                'paths' => ['resources/css/theme.css'],
                'buildPath' => 'theme-build',
            ],
            'header' => ['enabled' => true],
            'footer' => ['enabled' => true],
        ],
    ]);
});

it('can create a first theme without making it the default', function (): void {
    Blueprint::factory()->theme()->default()->create();

    $theme = CreateThemeAction::run(
        key: 'available',
        name: 'Available',
        default: false,
    );

    expect($theme->default)->toBeFalse()
        ->and(Theme::query()->default()->exists())->toBeFalse();
});

it('keeps active theme keys unique while allowing recreation after soft delete', function (): void {
    Blueprint::factory()->theme()->default()->create();

    $theme = Theme::factory()->createOne(['key' => 'agency']);

    expect($theme->active_key)->toBe('agency');
    expect(fn (): Theme => Theme::factory()->createOne(['key' => 'agency']))
        ->toThrow(QueryException::class);

    $theme->delete();

    expect($theme->fresh()?->active_key)->toBeNull();

    $replacement = Theme::factory()->createOne(['key' => 'agency']);

    expect($replacement->active_key)->toBe('agency');
});

it('preserves existing active preset when no new active preset is supplied', function (): void {
    $type = Blueprint::factory()->theme()->default()->create();
    Theme::factory()->createOne([
        'key' => 'editorial',
        'name' => 'Editorial',
        'blueprint_id' => $type->id,
        'meta' => [
            'active_preset' => 'existing',
            'assets' => ['resources/js/existing.js'],
        ],
    ]);

    $theme = CreateThemeAction::run(key: 'editorial', name: 'Editorial Updated');

    expect($theme->meta)->toMatchArray([
        'active_preset' => 'existing',
        'assets' => ['resources/js/existing.js'],
    ]);
});

it('uses registered theme definition assets when creating a theme without explicit assets', function (): void {
    Blueprint::factory()->theme()->default()->create();

    $registry = new ThemeRegistry;
    $registry->register(
        definition: new ThemeDefinitionData(
            key: 'saas',
            name: 'Velocity',
            description: 'SaaS theme',
            package: 'capell-app/theme-saas',
            previewImage: '/vendor/capell/themes/saas.jpg',
            tags: [],
            bestFit: [],
            presets: [
                new ThemePresetData(
                    key: 'velocity',
                    name: 'Velocity',
                    description: 'Velocity',
                    previewImage: '/vendor/capell/themes/saas.jpg',
                    values: [],
                ),
            ],
            assets: ['css' => 'vendor/capell/themes/saas.css'],
        ),
    );
    app()->instance(ThemeRegistry::class, $registry);

    $theme = CreateThemeAction::run(key: 'saas', name: 'Velocity');

    expect($theme->meta)->toMatchArray([
        'assets' => ['vendor/capell/themes/saas.css'],
    ]);
});

it('replaces legacy shared frontend css with registered child theme assets', function (): void {
    $type = Blueprint::factory()->theme()->default()->create();
    Theme::factory()->createOne([
        'key' => 'saas',
        'name' => 'Velocity',
        'blueprint_id' => $type->id,
        'meta' => [
            'assets' => ['resources/css/capell/frontend.css'],
            'assets_path' => 'build',
        ],
    ]);

    $registry = new ThemeRegistry;
    $registry->register(
        definition: new ThemeDefinitionData(
            key: 'saas',
            name: 'Velocity',
            description: 'SaaS theme',
            package: 'capell-app/theme-saas',
            previewImage: '/vendor/capell/themes/saas.jpg',
            tags: [],
            bestFit: [],
            presets: [
                new ThemePresetData(
                    key: 'velocity',
                    name: 'Velocity',
                    description: 'Velocity',
                    previewImage: '/vendor/capell/themes/saas.jpg',
                    values: [],
                ),
            ],
            assets: ['css' => 'vendor/capell/themes/saas.css'],
        ),
    );
    app()->instance(ThemeRegistry::class, $registry);

    $theme = CreateThemeAction::run(key: 'saas', name: 'Velocity');

    expect($theme->meta)->toMatchArray([
        'assets' => ['vendor/capell/themes/saas.css'],
        'assets_path' => 'build',
    ]);
});
