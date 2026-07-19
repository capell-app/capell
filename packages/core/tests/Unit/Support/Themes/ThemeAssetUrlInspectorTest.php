<?php

declare(strict_types=1);

use Capell\Core\Support\Themes\ThemeAssetUrlInspector;

it('allows ordinary root-relative navigation links', function (): void {
    expect(ThemeAssetUrlInspector::containsRootRelativeAssetUrl('<a href="/">Home</a>'))
        ->toBeFalse();
});

it('detects root-relative image and stylesheet assets', function (string $blade): void {
    expect(ThemeAssetUrlInspector::containsRootRelativeAssetUrl($blade))->toBeTrue();
})->with([
    'image source' => '<img src="/images/logo.png">',
    'stylesheet link' => '<link rel="stylesheet" href="/build/theme.css">',
]);

it('allows asset helpers and protocol-relative sources', function (string $blade): void {
    expect(ThemeAssetUrlInspector::containsRootRelativeAssetUrl($blade))->toBeFalse();
})->with([
    'frontend asset helper' => '<link rel="stylesheet" href="@frontendAsset(\'vendor/theme.css\')">',
    'protocol relative source' => '<img src="//cdn.example.test/logo.png">',
]);
