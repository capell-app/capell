<?php

declare(strict_types=1);

use Capell\Core\Enums\PresentationLoadingStrategy;
use Capell\Frontend\Data\FrontendAssetManifestData;
use Capell\Frontend\Data\FrontendAssetRequirementData;
use Capell\Frontend\Data\FrontendRuntimeManifestData;
use Capell\Frontend\Enums\RenderingStrategyEnum;

it('groups build assets by build path', function (): void {
    $manifest = new FrontendAssetManifestData(
        css: [
            new FrontendAssetRequirementData(
                handle: 'theme-css',
                kind: FrontendAssetRequirementData::KIND_CSS,
                source: 'resources/css/app.css',
                buildPath: 'build',
            ),
        ],
        js: [
            new FrontendAssetRequirementData(
                handle: 'theme-js',
                kind: FrontendAssetRequirementData::KIND_JS,
                source: 'resources/js/app.js',
                buildPath: 'build',
            ),
            new FrontendAssetRequirementData(
                handle: 'vendor-js',
                kind: FrontendAssetRequirementData::KIND_JS,
                source: 'resources/js/vendor.js',
                buildPath: 'vendor/capell',
            ),
        ],
        inline: [],
        preloads: [],
        runtime: FrontendRuntimeManifestData::forRenderingStrategy(RenderingStrategyEnum::FullLivewire),
    );

    expect($manifest->buildAssetsByPath())->toBe([
        'build' => ['resources/css/app.css', 'resources/js/app.js'],
        'vendor/capell' => ['resources/js/vendor.js'],
    ])
        ->and($manifest->hasJavaScript())->toBeTrue();
});

it('defaults legacy lazy javascript assets to modules', function (): void {
    app()->instance('url', new class
    {
        public function asset(string $path, ?bool $secure = null): string
        {
            return $path;
        }
    });

    $legacyAsset = new ReflectionClass(FrontendAssetRequirementData::class)
        ->newInstanceWithoutConstructor();

    $legacyAsset->handle = 'legacy-lazy-js';
    $legacyAsset->kind = FrontendAssetRequirementData::KIND_JS;
    $legacyAsset->source = 'resources/js/legacy.js';
    $legacyAsset->buildPath = null;
    $legacyAsset->defer = false;
    $legacyAsset->async = false;
    $legacyAsset->condition = 'widget-public-id';
    $legacyAsset->loadingStrategy = PresentationLoadingStrategy::Idle;

    $manifest = new FrontendAssetManifestData(
        css: [],
        js: [],
        inline: [],
        preloads: [],
        runtime: FrontendRuntimeManifestData::forRenderingStrategy(RenderingStrategyEnum::FullLivewire),
        lazy: [$legacyAsset],
    );

    expect($manifest->lazyAssetsByPublicId())->toBe([
        'widget-public-id' => [
            [
                'kind' => FrontendAssetRequirementData::KIND_JS,
                'url' => 'resources/js/legacy.js',
                'loading' => PresentationLoadingStrategy::Idle->value,
                'defer' => false,
                'async' => false,
                'module' => true,
            ],
        ],
    ]);
});
