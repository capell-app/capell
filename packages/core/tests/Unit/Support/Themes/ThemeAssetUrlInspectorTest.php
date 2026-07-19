<?php

declare(strict_types=1);

use Capell\Core\Support\Themes\ThemeAssetUrlInspector;

it('allows root-relative hrefs that do not load assets', function (string $blade): void {
    expect(ThemeAssetUrlInspector::containsRootRelativeAssetUrl($blade))->toBeFalse();
})->with([
    'navigation link' => '<a href="/">Home</a>',
    'canonical link' => '<link rel="canonical" href="/articles/example">',
    'alternate link' => '<link href="/fr" rel="alternate">',
]);

it('detects root-relative asset attributes', function (string $blade): void {
    expect(ThemeAssetUrlInspector::containsRootRelativeAssetUrl($blade))->toBeTrue();
})->with([
    'image source' => '<img src="/images/logo.png">',
    'stylesheet link' => '<link rel="stylesheet" href="/build/theme.css">',
    'stylesheet link with href first' => '<link href="/build/theme.css" rel="stylesheet">',
    'SVG use href' => '<svg><use href="/icons/sprite.svg#tick"></use></svg>',
    'SVG image href' => '<svg><image href="/images/diagram.png"></image></svg>',
    'SVG use xlink href' => '<svg><use xlink:href="/icons/sprite.svg#tick"></use></svg>',
    'SVG image xlink href' => '<svg><image xlink:href="/images/diagram.png"></image></svg>',
    'video poster' => '<video poster="/images/poster.jpg"></video>',
    'object data' => '<object data="/documents/example.pdf"></object>',
    'first srcset candidate' => '<img srcset="/images/small.jpg 480w, @frontendAsset(\'images/large.jpg\') 960w">',
    'later srcset candidate' => '<source srcset="@frontendAsset(\'images/small.jpg\') 480w, /images/large.jpg 960w">',
]);

it('allows asset helpers and protocol-relative sources', function (string $blade): void {
    expect(ThemeAssetUrlInspector::containsRootRelativeAssetUrl($blade))->toBeFalse();
})->with([
    'frontend asset helper' => '<link rel="stylesheet" href="@frontendAsset(\'vendor/theme.css\')">',
    'protocol relative source' => '<img src="//cdn.example.test/logo.png">',
    'protocol relative srcset candidates' => '<img srcset="//cdn.example.test/small.png 1x, //cdn.example.test/large.png 2x">',
    'absolute srcset candidates' => '<img srcset="https://cdn.example.test/small.png 1x, https://cdn.example.test/large.png 2x">',
    'asset helper srcset candidates' => '<img srcset="{{ @frontendAsset(\'small.png\') }} 1x, {{ @frontendAsset(\'large.png\') }} 2x">',
    'lazy source data attribute' => '<img data-src="/images/lazy.png">',
    'Alpine source binding' => '<img x-bind:src="/images/dynamic.png">',
    'Blade source binding' => '<img :src="/images/dynamic.png">',
    'lazy srcset data attribute' => '<img data-srcset="/images/lazy.png 1x">',
]);
