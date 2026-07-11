<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Blade;

it('compiles build assets from the frontend package', function (): void {
    $html = Blade::render('@buildAssets(["app.css", "app.js"], "build", "asset")');

    expect($html)
        ->not->toContain('@buildAssets')
        ->toContain('<link rel="stylesheet" href="http://localhost/build/app.css">')
        ->toContain('<script src="http://localhost/build/app.js"></script>');
});

it('defaults build assets to vite', function (): void {
    config()->set('app.url', 'http://localhost');

    $buildDirectory = 'vendor/capell-test-assets';
    $publicBuildDirectory = public_path($buildDirectory);

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

        expect(config('capell-frontend.asset_build_tool'))
            ->toBe('vite')
            ->and($html)
            ->not->toContain('resources/js/example.js')
            ->toContain('http://localhost/vendor/capell-test-assets/assets/example-123.js');
    } finally {
        @unlink($publicBuildDirectory . '/manifest.json');
        @rmdir($publicBuildDirectory);
    }
});
