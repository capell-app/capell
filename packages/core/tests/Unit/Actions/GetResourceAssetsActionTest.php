<?php

declare(strict_types=1);

use Capell\Core\Actions\GetResourceAssetsAction;
use Illuminate\Support\Facades\File;

it('resolves assets for a resource type', function (): void {
    $assets = GetResourceAssetsAction::run();

    expect($assets)->toBeArray();
});

it('discovers visible css and javascript resources while ignoring hidden and mismatched files', function (): void {
    $cssDirectory = resource_path('css/capell-test-assets');
    $jsDirectory = resource_path('js/capell-test-assets');

    File::ensureDirectoryExists($cssDirectory);
    File::ensureDirectoryExists($jsDirectory);
    File::put($cssDirectory . '/public.css', 'body {}');
    File::put($cssDirectory . '/ignored.js', 'console.log("ignored");');
    File::put($cssDirectory . '/.hidden.css', 'body {}');
    File::put($jsDirectory . '/public.js', 'console.log("ok");');
    File::put($jsDirectory . '/ignored.css', 'body {}');

    try {
        expect(GetResourceAssetsAction::run())->toContain(
            'resources/css/capell-test-assets/public.css',
            'resources/js/capell-test-assets/public.js',
        )->not->toContain(
            'resources/css/capell-test-assets/.hidden.css',
            'resources/css/capell-test-assets/ignored.js',
            'resources/js/capell-test-assets/ignored.css',
        );
    } finally {
        File::deleteDirectory($cssDirectory);
        File::deleteDirectory($jsDirectory);
    }
});
