<?php

declare(strict_types=1);

use Capell\Core\Enums\AssetComponentEnum;
use Capell\Core\Support\CapellCoreManager;
use Capell\Core\Support\Components\ComponentRegistry;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\File;

it('owns component registration discovery and selective operation reset', function (): void {
    $root = storage_path('framework/testing/component-registry-' . uniqid());
    File::ensureDirectoryExists($root . '/page-sections');
    File::put($root . '/page-sections/first.blade.php', '<section>First</section>');
    config(['capell.cache_path' => $root . '/cache']);

    $registry = new ComponentRegistry;
    $registry
        ->registerComponent(AssetComponentEnum::Card, AssetComponentEnum::Media, 'manual-card')
        ->registerComponent(AssetComponentEnum::Card, AssetComponentEnum::Media, 'ignored-duplicate')
        ->registerDiscoverableComponents($root, 'public');

    expect($registry->getComponent('PageSections', 'public.first'))->toBe('public.first')
        ->and($registry->getCoreComponents('Card'))->toBe(['Media' => 'manual-card'])
        ->and($registry->hasCachedComponents())->toBeFalse();

    $registry->cacheComponents();

    expect($registry->hasCachedComponents())->toBeTrue();

    File::delete($root . '/page-sections/first.blade.php');
    File::put($root . '/page-sections/second.blade.php', '<section>Second</section>');
    File::delete($registry->getComponentCachePath());

    $registry->flushOctaneState();

    expect($registry->getCoreComponents('Card'))->toBe(['Media' => 'manual-card'])
        ->and($registry->hasCachedComponents())->toBeFalse()
        ->and($registry->hasComponent('PageSections', 'public.first'))->toBeFalse()
        ->and($registry->getComponent('PageSections', 'public.second'))->toBe('public.second');

    File::deleteDirectory($root);
});

it('keeps the core manager as a method-for-method component registry adapter', function (): void {
    $manager = new CapellCoreManager;
    $registry = resolve(ComponentRegistry::class);

    $manager->registerComponent('Adapter', 'registered', 'adapter.registered');

    expect($manager->getComponents('Adapter'))->toBe(['registered' => 'adapter.registered'])
        ->and($registry->getComponents('Adapter'))->toBe(['registered' => 'adapter.registered'])
        ->and(CapellCoreManager::getComponentTypeFromDirectory('/tmp/page-sections'))->toBe('PageSections');
});

it('refuses a failed deletion result even when the component cache disappears', function (string $cache): void {
    $files = new Filesystem;
    $root = storage_path('framework/testing/component-removal-' . uniqid());
    config(['capell.cache_path' => $root . '/capell', 'filament.cache_path' => $root . '/filament']);
    $registry = new ComponentRegistry;
    $registry->cacheComponents();

    $files->ensureDirectoryExists($root . '/filament/panels');
    $files->put($root . '/filament/panels/activation.php', '<?php return [];');

    $path = $cache === 'filament' ? $root . '/filament' : $registry->getComponentCachePath();
    $failingFiles = Mockery::mock(Filesystem::class)->makePartial();
    $failingFiles->shouldReceive($cache === 'filament' ? 'deleteDirectory' : 'delete')->once()->with($path)
        ->andReturnUsing(static function () use ($files, $cache, $path): bool {
            $cache === 'filament' ? $files->deleteDirectory($path) : $files->delete($path);

            return false;
        });
    app()->instance(Filesystem::class, $failingFiles);

    try {
        expect(fn () => $registry->clearCachedComponentsOrFail())->toThrow(RuntimeException::class, __('capell-core::runtime-refresh.cache_refresh_required'));
        expect($files->exists($path))->toBeFalse()
            ->and($registry->hasCachedComponents())->toBeTrue();
    } finally {
        app()->instance(Filesystem::class, $files);
        $files->deleteDirectory($root);
    }
})->with(['capell', 'filament']);

it('removes persisted component caches from the configured Filament directory', function (): void {
    $root = storage_path('framework/testing/configured-component-cache-' . uniqid());
    config(['filament.cache_path' => $root]);
    File::ensureDirectoryExists($root . '/panels');
    File::put($root . '/panels/activation.php', '<?php return [];');

    try {
        new ComponentRegistry()->clearCachedComponentsOrFail();

        expect(File::exists($root))->toBeFalse();
    } finally {
        File::deleteDirectory($root);
    }
});

it('accepts the nullable default Filament cache configuration', function (): void {
    config(['filament.cache_path' => null]);

    new ComponentRegistry()->clearCachedComponentsOrFail();

    expect(File::exists(base_path('bootstrap/cache/filament')))->toBeFalse();
});

it('keeps general component clearing best-effort when a persisted cache cannot be deleted', function (): void {
    $files = Mockery::mock(Filesystem::class)->makePartial();
    $registry = new ComponentRegistry;
    $files->shouldReceive('delete')->once()->with($registry->getComponentCachePath())->andReturnFalse();
    $files->shouldReceive('deleteDirectory')->once()->with(base_path('bootstrap/cache/filament'))->andReturnFalse();
    app()->instance(Filesystem::class, $files);

    $registry->clearCachedComponents();

    expect($registry->hasCachedComponents())->toBeFalse();
});
