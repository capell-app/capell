<?php

declare(strict_types=1);

use Capell\Core\Support\Security\LockdownStaticCacheSwitcher;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\File;

beforeEach(function (): void {
    config()->set('filesystems.disks.page_cache.root', storage_path('framework/testing/page-cache'));
    File::deleteDirectory(config('filesystems.disks.page_cache.root'));

    $preservedCachePaths = glob(storage_path('framework/testing/page-cache.capell-live-*'));

    foreach (is_array($preservedCachePaths) ? $preservedCachePaths : [] as $path) {
        File::deleteDirectory($path);
    }
});

afterEach(function (): void {
    File::deleteDirectory(config('filesystems.disks.page_cache.root'));

    $preservedCachePaths = glob(storage_path('framework/testing/page-cache.capell-live-*'));

    foreach (is_array($preservedCachePaths) ? $preservedCachePaths : [] as $path) {
        File::deleteDirectory($path);
    }
});

it('preserves the live page cache and mirrors lockdown html into cached paths', function (): void {
    $root = config('filesystems.disks.page_cache.root');
    File::ensureDirectoryExists($root . '/about');
    File::put($root . '/index.html', '<h1>Live home</h1>');
    File::put($root . '/about/index.html', '<h1>Live about</h1>');

    $switcher = new LockdownStaticCacheSwitcher(new Filesystem);
    $state = $switcher->activate();

    expect(File::get($root . '/index.html'))->toContain('Service unavailable')
        ->and(File::get($root . '/about/index.html'))->toContain('Service unavailable')
        ->and($state['preserved_root'])->toBeString()
        ->and(File::get($state['preserved_root'] . '/about/index.html'))->toBe('<h1>Live about</h1>');

    $switcher->deactivate(['static_cache' => $state]);

    expect(File::get($root . '/index.html'))->toBe('<h1>Live home</h1>')
        ->and(File::get($root . '/about/index.html'))->toBe('<h1>Live about</h1>');
});
