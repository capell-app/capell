<?php

declare(strict_types=1);

use Capell\Frontend\Data\FrontendAssetManifestData;
use Capell\Frontend\Data\FrontendAssetRequirementData;
use Capell\Frontend\Data\FrontendRuntimeManifestData;
use Capell\Frontend\Enums\RenderingStrategyEnum;
use Capell\Frontend\Support\Assets\DefaultFrontendAssetManifestRenderer;
use Illuminate\Foundation\Mix;
use Illuminate\Foundation\Vite;
use Illuminate\Routing\UrlGenerator;

it('renders the existing blocking public asset shape by default', function (): void {
    config()->set('capell-frontend.asset_build_tool', 'public');

    $manifest = new FrontendAssetManifestData(
        css: [
            new FrontendAssetRequirementData(
                handle: 'theme-css',
                kind: FrontendAssetRequirementData::KIND_CSS,
                source: 'resources/css/theme.css',
                buildPath: 'build/theme',
            ),
            new FrontendAssetRequirementData(
                handle: 'static-theme-css',
                kind: FrontendAssetRequirementData::KIND_CSS,
                source: 'vendor/capell/themes/static.css',
            ),
        ],
        js: [
            new FrontendAssetRequirementData(
                handle: 'theme-js',
                kind: FrontendAssetRequirementData::KIND_JS,
                source: 'resources/js/theme.js',
                buildPath: 'build/theme',
            ),
        ],
        inline: [],
        preloads: [],
        runtime: FrontendRuntimeManifestData::forRenderingStrategy(RenderingStrategyEnum::BladeOnly),
    );

    $html = resolve(DefaultFrontendAssetManifestRenderer::class)->render($manifest)->toHtml();

    expect($html)
        ->toContain('<link rel="stylesheet" href="http://localhost/build/theme/resources/css/theme.css">')
        ->toContain('<script src="http://localhost/build/theme/resources/js/theme.js"></script>')
        ->toContain('<link href="http://localhost/vendor/capell/themes/static.css" rel="stylesheet" />');
});

it('falls back to public asset urls when vite cannot resolve a build asset', function (): void {
    config()->set('capell-frontend.asset_build_tool', 'vite');

    $vite = Mockery::mock(Vite::class);
    $vite->shouldReceive('isRunningHot')->once()->andReturnFalse();
    $vite->shouldNotReceive('__invoke');

    $renderer = new DefaultFrontendAssetManifestRenderer(
        vite: $vite,
        mix: resolve(Mix::class),
        url: resolve(UrlGenerator::class),
    );

    $manifest = new FrontendAssetManifestData(
        css: [
            new FrontendAssetRequirementData(
                handle: 'capell-generated-css',
                kind: FrontendAssetRequirementData::KIND_CSS,
                source: 'resources/css/capell/frontend.css',
                buildPath: 'build',
            ),
        ],
        js: [],
        inline: [],
        preloads: [],
        runtime: FrontendRuntimeManifestData::forRenderingStrategy(RenderingStrategyEnum::BladeOnly),
    );

    expect($renderer->render($manifest)->toHtml())
        ->toContain('<link rel="stylesheet" href="http://localhost/build/resources/css/capell/frontend.css">');
});
