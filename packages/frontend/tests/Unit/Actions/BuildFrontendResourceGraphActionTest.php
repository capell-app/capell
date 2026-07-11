<?php

declare(strict_types=1);

use Capell\Core\Models\Theme;
use Capell\Frontend\Actions\BuildFrontendResourceGraphAction;
use Capell\Frontend\Data\FrontendAssetManifestData;
use Capell\Frontend\Data\FrontendAssetRequirementData;
use Capell\Frontend\Data\FrontendRenderContextData;
use Capell\Frontend\Data\FrontendRuntimeManifestData;
use Capell\Frontend\Enums\RenderingStrategyEnum;
use Capell\Frontend\Support\Assets\FrontendResourceRegistry;
use Capell\Frontend\Support\Assets\ThemeResourceResolver;

it('builds a resource graph with resource group metadata and asset reasons', function (): void {
    $registry = new FrontendResourceRegistry;
    $registry->register(
        key: 'package.gallery',
        label: 'Gallery',
        assets: ['resources/css/gallery.css'],
        package: 'capell-app/gallery',
        defaultBuildPath: 'build',
    );

    $runtime = FrontendRuntimeManifestData::forRenderingStrategy(RenderingStrategyEnum::BladeOnly);
    $manifest = new FrontendAssetManifestData(
        css: [new FrontendAssetRequirementData('gallery', FrontendAssetRequirementData::KIND_CSS, 'resources/css/gallery.css', 'build')],
        js: [],
        inline: [],
        preloads: [],
        runtime: $runtime,
    );
    $theme = new Theme;
    $theme->meta = [];

    $context = new FrontendRenderContextData(
        page: null,
        site: null,
        language: null,
        layout: null,
        theme: $theme,
        runtimeManifest: $runtime,
    );

    $graph = app()->make(BuildFrontendResourceGraphAction::class, [
        'resolver' => new ThemeResourceResolver($registry),
    ])->handle($manifest, $context);

    expect($graph['resourceGroups'])->toHaveCount(1)
        ->and($graph['resourceGroups'][0]['key'])->toBe('package.gallery')
        ->and($graph['assets'][0]['reasons'][0])->toBe('Resource group package.gallery includes resources/css/gallery.css.');
});
