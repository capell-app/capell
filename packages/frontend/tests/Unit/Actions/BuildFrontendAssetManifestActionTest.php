<?php

declare(strict_types=1);

use Capell\Core\Enums\PresentationLoadingStrategy;
use Capell\Core\Models\Theme;
use Capell\Frontend\Actions\BuildFrontendAssetManifestAction;
use Capell\Frontend\Contracts\FrontendAssetContributor;
use Capell\Frontend\Data\FrontendAssetContextData;
use Capell\Frontend\Data\FrontendRuntimeManifestData;
use Capell\Frontend\Enums\RenderingStrategyEnum;
use Capell\Frontend\Support\Assets\FrontendResourceRegistry;
use Capell\Frontend\Tests\Fixtures\Autoload\BuildManifestFirstDuplicateContributor;
use Capell\Frontend\Tests\Fixtures\Autoload\BuildManifestSecondDuplicateContributor;
use Capell\Frontend\Tests\Fixtures\Autoload\BuildManifestTestContributor;

it('builds a deduplicated manifest from tagged asset contributors', function (): void {
    app()->bind(BuildManifestTestContributor::class);
    app()->tag(BuildManifestTestContributor::class, FrontendAssetContributor::TAG);

    $manifest = BuildFrontendAssetManifestAction::run(new FrontendAssetContextData(
        page: null,
        site: null,
        language: null,
        layout: null,
        theme: null,
        runtime: FrontendRuntimeManifestData::forRenderingStrategy(RenderingStrategyEnum::FullLivewire),
    ));

    expect(collect($manifest->css)->where('source', 'resources/css/app.css'))->toHaveCount(1)
        ->and(collect($manifest->js)->where('source', 'resources/js/app.js'))->toHaveCount(1)
        ->and($manifest->inline)->toHaveCount(1)
        ->and($manifest->preloads)->toHaveCount(1)
        ->and($manifest->buildAssetsByPath()['build'] ?? [])->toContain('resources/css/app.css')
        ->and($manifest->buildAssetsByPath()['build'] ?? [])->toContain('resources/js/app.js');
});

it('deduplicates equivalent assets across contributors with different handles', function (): void {
    app()->bind(BuildManifestFirstDuplicateContributor::class);
    app()->bind(BuildManifestSecondDuplicateContributor::class);
    app()->tag([BuildManifestFirstDuplicateContributor::class, BuildManifestSecondDuplicateContributor::class], FrontendAssetContributor::TAG);

    $manifest = BuildFrontendAssetManifestAction::run(new FrontendAssetContextData(
        page: null,
        site: null,
        language: null,
        layout: null,
        theme: null,
        runtime: FrontendRuntimeManifestData::forRenderingStrategy(RenderingStrategyEnum::BladeOnly),
    ));

    expect($manifest->css)->toHaveCount(1)
        ->and($manifest->css[0]->handle)->toBe('first');
});

it('adds selected widget resource groups to the manifest with loading overrides', function (): void {
    $registry = new FrontendResourceRegistry;
    $registry->register(
        key: 'package.gallery',
        label: 'Gallery',
        assets: [
            ['source' => 'resources/css/gallery.css'],
            [
                'source' => 'resources/js/gallery.js',
                'loadingStrategy' => PresentationLoadingStrategy::Visible->value,
            ],
        ],
        defaultBuildPath: 'build',
    );
    app()->instance(FrontendResourceRegistry::class, $registry);

    $theme = new Theme;
    $theme->meta = [];

    $manifest = BuildFrontendAssetManifestAction::run(new FrontendAssetContextData(
        page: null,
        site: null,
        language: null,
        layout: null,
        theme: $theme,
        runtime: FrontendRuntimeManifestData::forRenderingStrategy(RenderingStrategyEnum::BladeOnly),
        widgetResourceUsages: [
            [
                'widgetKey' => 'gallery',
                'resourceGroup' => 'package.gallery',
                'publicId' => 'widget-public-id',
                'loadingStrategy' => PresentationLoadingStrategy::Idle->value,
            ],
        ],
    ));

    expect($manifest->css)->toBe([])
        ->and($manifest->js)->toBe([])
        ->and($manifest->lazy)->toHaveCount(2)
        ->and($manifest->lazy[0]->source)->toBe('resources/css/gallery.css')
        ->and($manifest->lazy[0]->condition)->toBe('widget-public-id')
        ->and($manifest->lazy[0]->loadingStrategy)->toBe(PresentationLoadingStrategy::Idle)
        ->and($manifest->rawRequirements)->toHaveCount(2);
});

it('reads typed loading strategies from structured widget usage presentation data', function (): void {
    $registry = new FrontendResourceRegistry;
    $registry->register(
        key: 'package.structured',
        label: 'Structured',
        assets: [[
            'source' => '/assets/structured.js',
            'loadingStrategy' => PresentationLoadingStrategy::Interaction->value,
        ]],
    );
    app()->instance(FrontendResourceRegistry::class, $registry);

    $theme = new Theme;
    $theme->meta = [];

    $manifest = BuildFrontendAssetManifestAction::run(new FrontendAssetContextData(
        page: null,
        site: null,
        language: null,
        layout: null,
        theme: $theme,
        runtime: FrontendRuntimeManifestData::forRenderingStrategy(RenderingStrategyEnum::BladeOnly),
        widgetResourceUsages: [[
            'widgetKey' => 'structured',
            'resourceGroup' => 'package.structured',
            'publicId' => 'structured-public-id',
            'presentation' => [
                'loadingStrategy' => PresentationLoadingStrategy::Visible,
            ],
        ]],
    ));

    expect($manifest->lazy)->toHaveCount(1)
        ->and($manifest->lazy[0]->loadingStrategy)->toBe(PresentationLoadingStrategy::Visible)
        ->and($manifest->lazy[0]->condition)->toBe('structured-public-id');
});

it('consolidates a shared resolved URL by kind and promotes it to the earliest strategy', function (): void {
    $registry = new FrontendResourceRegistry;
    $registry->register(
        key: 'package.visible',
        label: 'Visible',
        assets: [[
            'handle' => 'visible-shared',
            'kind' => 'js',
            'source' => '/build/shared.js',
            'loadingStrategy' => PresentationLoadingStrategy::Visible->value,
        ]],
    );
    $registry->register(
        key: 'package.eager',
        label: 'Eager',
        assets: [[
            'handle' => 'eager-shared',
            'kind' => 'js',
            'source' => 'shared.js',
            'loadingStrategy' => PresentationLoadingStrategy::Eager->value,
        ]],
        defaultBuildPath: 'build',
    );
    app()->instance(FrontendResourceRegistry::class, $registry);
    config()->set('capell-frontend.asset_build_tool', 'public');

    $theme = new Theme;
    $theme->meta = [];

    $manifest = BuildFrontendAssetManifestAction::run(new FrontendAssetContextData(
        page: null,
        site: null,
        language: null,
        layout: null,
        theme: $theme,
        runtime: FrontendRuntimeManifestData::forRenderingStrategy(RenderingStrategyEnum::BladeOnly),
        widgetResourceUsages: [
            [
                'widgetKey' => 'visible',
                'resourceGroup' => 'package.visible',
                'publicId' => 'visible-public-id',
                'loadingStrategy' => PresentationLoadingStrategy::Visible->value,
            ],
            [
                'widgetKey' => 'eager',
                'resourceGroup' => 'package.eager',
                'publicId' => 'eager-public-id',
                'loadingStrategy' => PresentationLoadingStrategy::Eager->value,
            ],
        ],
    ));

    expect($manifest->js)->toHaveCount(1)
        ->and($manifest->js[0]->loadingStrategy)->toBe(PresentationLoadingStrategy::Eager)
        ->and($manifest->lazy)->toBe([])
        ->and($manifest->lazyAssetsByPublicId())->toHaveKey('visible-public-id')
        ->and($manifest->lazyAssetsByPublicId()['visible-public-id'])->toHaveCount(1)
        ->and($manifest->lazyAssetsByPublicId()['visible-public-id'][0]['url'])->toEndWith('/build/shared.js');
});

it('promotes lazy strategies deterministically and keeps every public id mapping', function (): void {
    $registry = new FrontendResourceRegistry;
    foreach ([
        'interaction' => PresentationLoadingStrategy::Interaction,
        'idle' => PresentationLoadingStrategy::Idle,
        'visible' => PresentationLoadingStrategy::Visible,
    ] as $key => $strategy) {
        $registry->register(
            key: 'package.' . $key,
            label: ucfirst($key),
            assets: [[
                'handle' => $key . '-shared',
                'kind' => 'css',
                'source' => '/assets/shared.css',
                'loadingStrategy' => $strategy->value,
            ]],
        );
    }

    app()->instance(FrontendResourceRegistry::class, $registry);

    $theme = new Theme;
    $theme->meta = [];

    $usages = collect(['interaction', 'idle', 'visible'])
        ->map(fn (string $key): array => [
            'widgetKey' => $key,
            'resourceGroup' => 'package.' . $key,
            'publicId' => $key . '-public-id',
            'loadingStrategy' => $key,
        ])
        ->all();

    $manifest = BuildFrontendAssetManifestAction::run(new FrontendAssetContextData(
        page: null,
        site: null,
        language: null,
        layout: null,
        theme: $theme,
        runtime: FrontendRuntimeManifestData::forRenderingStrategy(RenderingStrategyEnum::BladeOnly),
        widgetResourceUsages: $usages,
    ));

    expect($manifest->lazy)->toHaveCount(1)
        ->and($manifest->lazy[0]->loadingStrategy)->toBe(PresentationLoadingStrategy::Visible)
        ->and($manifest->lazyAssetsByPublicId())->toHaveKeys([
            'interaction-public-id',
            'idle-public-id',
            'visible-public-id',
        ]);
});
