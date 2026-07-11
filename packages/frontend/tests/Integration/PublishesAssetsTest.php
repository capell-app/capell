<?php

declare(strict_types=1);

use Illuminate\Support\ServiceProvider;

test('capell-frontend assets publish under both the capell tag and the laravel-assets group', function (): void {
    $target = public_path('vendor/capell-frontend');

    expect(ServiceProvider::$publishGroups)
        ->toHaveKey('capell-frontend-assets')
        ->toHaveKey('laravel-assets');

    // Both groups must publish the prebuilt capell-frontend assets to the same
    // public target, so `vendor:publish --tag=laravel-assets` (the conventional
    // skeleton/deploy hook) republishes them alongside framework + Filament assets.
    expect(array_values(ServiceProvider::$publishGroups['capell-frontend-assets']))
        ->toContain($target);
    expect(array_values(ServiceProvider::$publishGroups['laravel-assets']))
        ->toContain($target);
});

test('capell-frontend published build includes the default theme css entry', function (): void {
    $manifest = json_decode(
        file_get_contents(__DIR__ . '/../../publishes/build/manifest.json') ?: '[]',
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect($manifest)
        ->toHaveKey('resources/css/capell-frontend.css')
        ->and($manifest['resources/css/capell-frontend.css']['file'])->toBe('capell-frontend.css')
        ->and(is_file(__DIR__ . '/../../publishes/build/capell-frontend.css'))->toBeTrue();
});
