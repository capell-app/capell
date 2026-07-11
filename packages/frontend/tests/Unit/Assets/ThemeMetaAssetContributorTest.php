<?php

declare(strict_types=1);

use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Theme;
use Capell\Frontend\Data\FrontendAssetContextData;
use Capell\Frontend\Data\FrontendAssetManifestData;
use Capell\Frontend\Data\FrontendAssetRequirementData;
use Capell\Frontend\Data\FrontendRuntimeManifestData;
use Capell\Frontend\Enums\RenderingStrategyEnum;
use Capell\Frontend\Support\Assets\ThemeMetaAssetContributor;
use Illuminate\Support\Facades\File;

it('uses clean editor theme assets before legacy theme meta', function (): void {
    $theme = new Theme;
    $theme->meta = [
        'assets' => ['resources/css/legacy.css'],
        'assets_path' => 'legacy-build',
        'editor' => [
            'assets' => [
                'paths' => ['resources/css/editor.css'],
                'buildPath' => 'editor-build',
            ],
        ],
    ];

    $requirements = (new ThemeMetaAssetContributor)->requirements(new FrontendAssetContextData(
        page: null,
        site: null,
        language: null,
        layout: null,
        theme: $theme,
        runtime: FrontendRuntimeManifestData::forRenderingStrategy(RenderingStrategyEnum::BladeOnly),
    ));

    expect($requirements)->toHaveCount(1)
        ->and($requirements[0]->source)->toBe('resources/css/editor.css')
        ->and($requirements[0]->buildPath)->toBe('editor-build');
});

it('keeps theme css as a compatibility fallback for blade-only pages', function (): void {
    $theme = new Theme;
    $theme->meta = [
        'assets' => ['resources/css/app.css', 'resources/js/app.js'],
        'assets_path' => 'build',
    ];

    $requirements = (new ThemeMetaAssetContributor)->requirements(new FrontendAssetContextData(
        page: null,
        site: null,
        language: null,
        layout: null,
        theme: $theme,
        runtime: FrontendRuntimeManifestData::forRenderingStrategy(RenderingStrategyEnum::BladeOnly),
    ));

    expect($requirements)->toHaveCount(1)
        ->and($requirements[0])->toBeInstanceOf(FrontendAssetRequirementData::class)
        ->and($requirements[0]->kind)->toBe(FrontendAssetRequirementData::KIND_CSS)
        ->and($requirements[0]->source)->toBe('resources/css/app.css')
        ->and($requirements[0]->buildPath)->toBe('build');
});

it('allows legacy theme javascript when the page runtime opts into interactivity', function (): void {
    $theme = new Theme;
    $theme->meta = [
        'assets' => ['resources/css/app.css', 'resources/js/app.js'],
        'assets_path' => 'build',
    ];

    $requirements = (new ThemeMetaAssetContributor)->requirements(new FrontendAssetContextData(
        page: null,
        site: null,
        language: null,
        layout: null,
        theme: $theme,
        runtime: FrontendRuntimeManifestData::forRenderingStrategy(RenderingStrategyEnum::FullLivewire),
    ));

    expect($requirements)->toHaveCount(2)
        ->and(collect($requirements)->pluck('source')->all())->toBe([
            'resources/css/app.css',
            'resources/js/app.js',
        ]);
});

it('falls back to the default build path when theme assets path is null', function (): void {
    $theme = new Theme;
    $theme->meta = [
        'assets' => ['resources/css/app.css'],
        'assets_path' => null,
    ];

    $requirements = (new ThemeMetaAssetContributor)->requirements(new FrontendAssetContextData(
        page: null,
        site: null,
        language: null,
        layout: null,
        theme: $theme,
        runtime: FrontendRuntimeManifestData::forRenderingStrategy(RenderingStrategyEnum::BladeOnly),
    ));

    expect($requirements)->toHaveCount(1)
        ->and($requirements[0]->buildPath)->toBe('build');
});

it('treats published vendor theme css as a static active theme asset', function (): void {
    File::ensureDirectoryExists(public_path('vendor/capell/themes'));
    File::put(public_path('vendor/capell/themes/saas.css'), 'body {}');

    $theme = new Theme;
    $theme->meta = [
        'assets' => ['vendor/capell/themes/saas.css'],
        'assets_path' => 'build',
    ];

    $requirements = (new ThemeMetaAssetContributor)->requirements(new FrontendAssetContextData(
        page: null,
        site: null,
        language: null,
        layout: null,
        theme: $theme,
        runtime: FrontendRuntimeManifestData::forRenderingStrategy(RenderingStrategyEnum::BladeOnly),
    ));

    expect($requirements)->toHaveCount(1)
        ->and($requirements[0]->kind)->toBe(FrontendAssetRequirementData::KIND_CSS)
        ->and($requirements[0]->source)->toBe('vendor/capell/themes/saas.css')
        ->and($requirements[0]->buildPath)->toBeNull();
});

it('treats the published default theme css as a static active theme asset', function (): void {
    File::ensureDirectoryExists(public_path('vendor/capell-frontend'));
    File::put(public_path('vendor/capell-frontend/capell-frontend.css'), 'body {}');

    $theme = new Theme;
    $theme->meta = [
        'assets' => ['vendor/capell-frontend/capell-frontend.css'],
        'assets_path' => null,
    ];

    $requirements = (new ThemeMetaAssetContributor)->requirements(new FrontendAssetContextData(
        page: null,
        site: null,
        language: null,
        layout: null,
        theme: $theme,
        runtime: FrontendRuntimeManifestData::forRenderingStrategy(RenderingStrategyEnum::BladeOnly),
    ));

    expect($requirements)->toHaveCount(1)
        ->and($requirements[0]->kind)->toBe(FrontendAssetRequirementData::KIND_CSS)
        ->and($requirements[0]->source)->toBe('vendor/capell-frontend/capell-frontend.css')
        ->and($requirements[0]->buildPath)->toBeNull();
});

it('skips missing static vendor theme css assets', function (): void {
    File::delete(public_path('vendor/capell/themes/missing-theme.css'));

    $theme = new Theme;
    $theme->meta = [
        'assets' => ['vendor/capell/themes/missing-theme.css'],
        'assets_path' => 'build',
    ];

    $requirements = (new ThemeMetaAssetContributor)->requirements(new FrontendAssetContextData(
        page: null,
        site: null,
        language: null,
        layout: null,
        theme: $theme,
        runtime: FrontendRuntimeManifestData::forRenderingStrategy(RenderingStrategyEnum::BladeOnly),
    ));

    expect($requirements)->toBe([]);
});

it('does not include static vendor theme assets in vite build groups', function (): void {
    $manifest = new FrontendAssetManifestData(
        css: [
            new FrontendAssetRequirementData(
                handle: 'theme-meta:saas',
                kind: FrontendAssetRequirementData::KIND_CSS,
                source: 'vendor/capell/themes/saas.css',
            ),
        ],
        js: [],
        inline: [],
        preloads: [],
        runtime: FrontendRuntimeManifestData::forRenderingStrategy(RenderingStrategyEnum::BladeOnly),
    );

    expect($manifest->buildAssetsByPath())->toBe([]);
});

it('uses loaded theme blueprint meta as the public asset fallback', function (): void {
    $type = Blueprint::factory()->theme()->create([
        'meta' => [
            'assets' => ['resources/css/blueprint.css'],
            'assets_path' => 'blueprint-build',
        ],
    ]);
    $theme = Theme::factory()
        ->for($type, 'blueprint')
        ->create(['meta' => []])
        ->load('blueprint');

    $requirements = (new ThemeMetaAssetContributor)->requirements(new FrontendAssetContextData(
        page: null,
        site: null,
        language: null,
        layout: null,
        theme: $theme,
        runtime: FrontendRuntimeManifestData::forRenderingStrategy(RenderingStrategyEnum::BladeOnly),
    ));

    expect($requirements)->toHaveCount(1)
        ->and($requirements[0]->source)->toBe('resources/css/blueprint.css')
        ->and($requirements[0]->buildPath)->toBe('blueprint-build');
});

it('does not fall back to blueprint assets once editor asset state exists', function (): void {
    $type = Blueprint::factory()->theme()->create([
        'meta' => [
            'assets' => ['resources/css/blueprint.css'],
            'assets_path' => 'blueprint-build',
        ],
    ]);
    $theme = Theme::factory()
        ->for($type, 'blueprint')
        ->create([
            'meta' => [
                'editor' => [
                    'assets' => [
                        'paths' => [],
                        'buildPath' => null,
                    ],
                ],
            ],
        ])
        ->load('blueprint');

    $requirements = (new ThemeMetaAssetContributor)->requirements(new FrontendAssetContextData(
        page: null,
        site: null,
        language: null,
        layout: null,
        theme: $theme,
        runtime: FrontendRuntimeManifestData::forRenderingStrategy(RenderingStrategyEnum::BladeOnly),
    ));

    expect($requirements)->toBe([]);
});
