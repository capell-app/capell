<?php

declare(strict_types=1);

use Capell\Core\Models\Theme;
use Capell\Frontend\Data\Assets\PublicResourceSourceData;
use Capell\Frontend\Data\FrontendResourceContextData;
use Capell\Frontend\Data\FrontendRuntimeManifestData;
use Capell\Frontend\Enums\FrontendResourceKind;
use Capell\Frontend\Enums\RenderingStrategyEnum;
use Capell\Frontend\Support\Assets\ThemeMetaAssetContributor;

it('contributes local theme metadata as typed public resources', function (): void {
    $theme = new Theme;
    $theme->meta = ['assets' => ['vendor/theme/theme.css']];

    $contributions = (new ThemeMetaAssetContributor)->resources(themeResourceContext($theme));

    expect($contributions)->toHaveCount(1)
        ->and($contributions[0]->resource->kind)->toBe(FrontendResourceKind::Style)
        ->and($contributions[0]->resource->source)->toBeInstanceOf(PublicResourceSourceData::class)
        ->and($contributions[0]->resource->source->path)->toBe('vendor/theme/theme.css');
});

it('rejects remote inline protocol-relative and traversal metadata sources', function (): void {
    $theme = new Theme;
    $theme->meta = ['assets' => [
        'https://cdn.example.com/app.js',
        '//cdn.example.com/app.js',
        'javascript:alert(1)',
        '../private/app.js',
    ]];

    expect((new ThemeMetaAssetContributor)->resources(themeResourceContext($theme)))->toBe([]);
});

it('omits theme javascript from a blade-only runtime', function (): void {
    $theme = new Theme;
    $theme->meta = ['assets' => ['vendor/theme/app.js']];

    expect((new ThemeMetaAssetContributor)->resources(themeResourceContext($theme)))->toBe([]);
});

function themeResourceContext(Theme $theme): FrontendResourceContextData
{
    return new FrontendResourceContextData(
        page: null,
        site: null,
        language: null,
        layout: null,
        theme: $theme,
        runtime: FrontendRuntimeManifestData::forRenderingStrategy(RenderingStrategyEnum::BladeOnly),
    );
}
