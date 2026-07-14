<?php

declare(strict_types=1);

use Capell\Core\Enums\PresentationLoadingStrategy;
use Capell\Frontend\Data\Assets\FrontendResourceData;
use Capell\Frontend\Data\Assets\InlineResourceSourceData;
use Capell\Frontend\Data\Assets\PublicResourceSourceData;
use Capell\Frontend\Enums\FrontendResourceKind;
use Capell\Frontend\Enums\FrontendResourcePlacement;
use Capell\Frontend\Enums\ScriptExecutionMode;

it('applies safe defaults through named resource factories', function (): void {
    $style = FrontendResourceData::style(
        handle: 'vendor/package:gallery-style',
        package: 'vendor/package',
        source: new PublicResourceSourceData('vendor/gallery/gallery.css'),
    );
    $module = FrontendResourceData::moduleScript(
        handle: 'vendor/package:gallery-module',
        package: 'vendor/package',
        source: new PublicResourceSourceData('vendor/gallery/gallery.js'),
    );
    $classic = FrontendResourceData::classicScript(
        handle: 'vendor/package:gallery-classic',
        package: 'vendor/package',
        source: new PublicResourceSourceData('vendor/gallery/legacy.js'),
    );
    $inlineStyle = FrontendResourceData::inlineStyle(
        handle: 'vendor/package:inline-style',
        package: 'vendor/package',
        content: ':root { --gallery-gap: 1rem; }',
    );
    $inlineScript = FrontendResourceData::inlineScript(
        handle: 'vendor/package:inline-script',
        package: 'vendor/package',
        content: 'window.galleryReady = true;',
    );

    expect($style)
        ->kind->toBe(FrontendResourceKind::Style)
        ->placement->toBe(FrontendResourcePlacement::Head)
        ->loadingStrategy->toBe(PresentationLoadingStrategy::Eager)
        ->executionMode->toBeNull()
        ->defer->toBeFalse()
        ->async->toBeFalse()
        ->and($module)
        ->kind->toBe(FrontendResourceKind::ModuleScript)
        ->placement->toBe(FrontendResourcePlacement::Head)
        ->executionMode->toBe(ScriptExecutionMode::Module)
        ->defer->toBeFalse()
        ->and($classic)
        ->kind->toBe(FrontendResourceKind::ClassicScript)
        ->placement->toBe(FrontendResourcePlacement::Head)
        ->executionMode->toBe(ScriptExecutionMode::Classic)
        ->defer->toBeTrue()
        ->and($inlineStyle->source)->toBeInstanceOf(InlineResourceSourceData::class)
        ->and($inlineStyle->placement)->toBe(FrontendResourcePlacement::Head)
        ->and($inlineScript->placement)->toBe(FrontendResourcePlacement::BodyEnd);
});

it('rejects invalid handles and package owners', function (string $handle, string $package): void {
    FrontendResourceData::style(
        handle: $handle,
        package: $package,
        source: new PublicResourceSourceData('vendor/gallery/gallery.css'),
    );
})->with([
    'blank handle' => ['', 'vendor/package'],
    'whitespace handle' => ['vendor/package:gallery style', 'vendor/package'],
    'unsafe handle' => ['<script>', 'vendor/package'],
    'missing composer vendor' => ['vendor/package:style', 'package'],
])->throws(InvalidArgumentException::class);

it('rejects source and resource kind mismatches', function (): void {
    new FrontendResourceData(
        handle: 'vendor/package:invalid',
        package: 'vendor/package',
        kind: FrontendResourceKind::InlineStyle,
        source: new PublicResourceSourceData('vendor/gallery/gallery.css'),
    );
})->throws(InvalidArgumentException::class);

it('rejects asynchronous resources participating in dependencies', function (): void {
    FrontendResourceData::classicScript(
        handle: 'vendor/package:async-plugin',
        package: 'vendor/package',
        source: new PublicResourceSourceData('vendor/gallery/plugin.js'),
        dependsOn: ['vendor/package:library'],
        async: true,
    );
})->throws(InvalidArgumentException::class, 'Async resources cannot declare dependencies');
