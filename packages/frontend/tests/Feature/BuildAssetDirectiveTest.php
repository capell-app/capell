<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Blade;

it('compiles build assets from the frontend package', function (): void {
    $html = Blade::render('@buildAssets(["app.css", "app.js"], "build", "asset")');
    $appUrl = rtrim((string) config('app.url'), '/');

    expect($html)
        ->not->toContain('@buildAssets')
        ->toContain('<link rel="stylesheet" href="' . $appUrl . '/build/app.css">')
        ->toContain('<script src="' . $appUrl . '/build/app.js"></script>');
});

it('defaults build assets to vite', function (): void {
    $buildDirectory = 'vendor/capell-test-assets';
    $publicBuildDirectory = public_path($buildDirectory);
    $appUrl = rtrim((string) config('app.url'), '/');

    if (! is_dir($publicBuildDirectory)) {
        mkdir($publicBuildDirectory, 0777, true);
    }

    file_put_contents(
        $publicBuildDirectory . '/manifest.json',
        json_encode([
            'resources/js/example.js' => [
                'src' => 'resources/js/example.js',
                'file' => 'assets/example-123.js',
            ],
        ], JSON_PRETTY_PRINT),
    );

    try {
        $html = Blade::render('@buildAssets(["resources/js/example.js"], "vendor/capell-test-assets")');

        expect($html)
            ->not->toContain('resources/js/example.js')
            ->toContain($appUrl . '/vendor/capell-test-assets/assets/example-123.js');
    } finally {
        @unlink($publicBuildDirectory . '/manifest.json');
        @rmdir($publicBuildDirectory);
    }
});
