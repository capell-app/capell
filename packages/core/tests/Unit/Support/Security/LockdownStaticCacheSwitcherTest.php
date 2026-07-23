<?php

declare(strict_types=1);

use Capell\Core\Support\Deployment\ReleaseRootWriteGuard;
use Capell\Core\Support\Hosting\MultiNodeTopologyGuard;
use Capell\Core\Support\Security\LockdownStaticCacheSwitcher;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\File;

beforeEach(function (): void {
    config()->set('capell.multi_node', false);
    config()->set('capell.release_root_mode', 'mutable');
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

    $switcher = new LockdownStaticCacheSwitcher(
        new Filesystem,
        new MultiNodeTopologyGuard,
        new ReleaseRootWriteGuard,
    );
    $state = $switcher->activate();

    expect(File::get($root . '/index.html'))->toContain('Service unavailable')
        ->and(File::get($root . '/about/index.html'))->toContain('Service unavailable')
        ->and($state['preserved_root'])->toBeString()
        ->and(File::get($state['preserved_root'] . '/about/index.html'))->toBe('<h1>Live about</h1>');

    $switcher->deactivate(['static_cache' => $state]);

    expect(File::get($root . '/index.html'))->toBe('<h1>Live home</h1>')
        ->and(File::get($root . '/about/index.html'))->toBe('<h1>Live about</h1>');
});

it('refuses to mutate a single node page cache in a multi-node installation', function (): void {
    config()->set('capell.multi_node', true);
    $root = config('filesystems.disks.page_cache.root');
    File::ensureDirectoryExists($root);
    File::put($root . '/index.html', '<h1>Live home</h1>');

    $switcher = new LockdownStaticCacheSwitcher(
        new Filesystem,
        new MultiNodeTopologyGuard,
        new ReleaseRootWriteGuard,
    );

    expect(fn (): array => $switcher->activate())
        ->toThrow(RuntimeException::class, 'Lockdown static cache activation cannot run while CAPELL_MULTI_NODE=true');

    expect(File::get($root . '/index.html'))->toBe('<h1>Live home</h1>');
});

it('refuses to restore a single node page cache in a multi-node installation', function (): void {
    config()->set('capell.multi_node', true);
    $root = config('filesystems.disks.page_cache.root');
    File::ensureDirectoryExists($root);
    File::put($root . '/index.html', '<h1>Lockdown</h1>');

    $switcher = new LockdownStaticCacheSwitcher(
        new Filesystem,
        new MultiNodeTopologyGuard,
        new ReleaseRootWriteGuard,
    );

    expect(function () use ($root, $switcher): void {
        $switcher->deactivate(['static_cache' => ['root' => $root]]);
    })
        ->toThrow(RuntimeException::class, 'Lockdown static cache deactivation cannot run while CAPELL_MULTI_NODE=true');

    expect(File::get($root . '/index.html'))->toBe('<h1>Lockdown</h1>');
});

it('refuses to mutate a page cache inside an immutable release root', function (): void {
    config()->set('capell.release_root_mode', 'immutable');
    $root = config('filesystems.disks.page_cache.root');
    File::ensureDirectoryExists($root);
    File::put($root . '/index.html', '<h1>Live home</h1>');

    $switcher = new LockdownStaticCacheSwitcher(
        new Filesystem,
        new MultiNodeTopologyGuard,
        new ReleaseRootWriteGuard,
    );

    expect(fn (): array => $switcher->activate())
        ->toThrow(RuntimeException::class, 'Switching the lockdown page cache is blocked because CAPELL_RELEASE_ROOT_MODE is immutable');

    expect(File::get($root . '/index.html'))->toBe('<h1>Live home</h1>');
});
