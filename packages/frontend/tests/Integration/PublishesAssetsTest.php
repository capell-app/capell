<?php

declare(strict_types=1);

use Capell\Tests\Support\OwnedApplicationPaths;
use Illuminate\Support\Facades\Artisan;

it('publishes frontend assets through the package and conventional deployment tags', function (string $tag): void {
    $workspace = new OwnedApplicationPaths(app());
    try {
        expect(Artisan::call('vendor:publish', ['--tag' => $tag, '--force' => true]))->toBe(0);
        $manifestPath = public_path('vendor/capell-frontend/manifest.json');
        expect($manifestPath)->toBeFile();
        $manifest = json_decode((string) file_get_contents($manifestPath), true, flags: JSON_THROW_ON_ERROR);
        foreach (['resources/css/capell-frontend.css', 'resources/js/stylesheet-recovery.js'] as $entry) {
            expect(public_path('vendor/capell-frontend/' . $manifest[$entry]['file']))->toBeFile();
        }
    } finally {
        $workspace->restore();
    }
})->with(['capell-frontend-assets', 'laravel-assets']);

it('capell-frontend published build includes the default theme css entry', function (): void {
    $manifest = json_decode(
        file_get_contents(__DIR__ . '/../../publishes/build/manifest.json') ?: '[]',
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect($manifest)
        ->toHaveKey('resources/css/capell-frontend.css')
        ->toHaveKey('resources/js/stylesheet-recovery.js')
        ->and($manifest['resources/css/capell-frontend.css']['file'])->toBe('capell-frontend.css')
        ->and($manifest['resources/js/stylesheet-recovery.js']['file'])->toBe('stylesheet-recovery.js')
        ->and(is_file(__DIR__ . '/../../publishes/build/capell-frontend.css'))->toBeTrue()
        ->and(is_file(__DIR__ . '/../../publishes/build/stylesheet-recovery.js'))->toBeTrue();
});
