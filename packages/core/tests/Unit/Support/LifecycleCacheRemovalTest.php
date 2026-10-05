<?php

declare(strict_types=1);

use Capell\Core\Actions\EnablePackageAction;
use Capell\Core\Actions\InstallPackageAction;
use Capell\Core\Actions\RuntimeRefresh\RefreshInstalledPackageRuntimeAction;
use Capell\Core\Enums\CacheEnum;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Support\Cache\CapellCacheManager;
use Capell\Core\Support\PackageRegistry\CapellPackageRegistry;
use Capell\Core\Tests\Unit\Support\Fixtures\RefusesCacheRemovalStore;
use Illuminate\Cache\Repository;
use Illuminate\Support\Facades\Cache;

it('refuses lifecycle cache invalidation when a persisted cache key cannot be removed', function (string $key, bool $reportedSuccess, bool $removeValue): void {
    $store = new RefusesCacheRemovalStore($reportedSuccess, $removeValue);
    Cache::extend('removal-fixture', fn (): Repository => new Repository($store));
    config(['cache.default' => 'removal-fixture', 'cache.stores.removal-fixture.driver' => 'removal-fixture']);
    CapellCore::setToCache($key, ['stale']);
    $store->refuseRemoval = true;

    try {
        expect(fn () => resolve(CapellPackageRegistry::class)->clearExtensionCacheOrFail())
            ->toThrow(RuntimeException::class, __('capell::runtime-refresh.cache_refresh_required'));
    } finally {
        $store->refuseRemoval = false;
        Cache::purge('removal-fixture');
    }
})->with([CacheEnum::ExtensionInstalledNames->value, CacheEnum::ExtensionPackages->value])
    ->with(['false with retained value' => [false, false], 'false with removed value' => [false, true]]);

it('accepts absent lifecycle cache keys without a removal attempt', function (): void {
    $store = new RefusesCacheRemovalStore(false, false);
    Cache::extend('removal-fixture', fn (): Repository => new Repository($store));
    config(['cache.default' => 'removal-fixture', 'cache.stores.removal-fixture.driver' => 'removal-fixture']);
    $store->refuseRemoval = true;

    try {
        resolve(CapellPackageRegistry::class)->clearExtensionCacheOrFail();
        expect($store->removalAttempts)->toBe(0)
            ->and(CapellCore::getInstalledExtensionNames())->toBeArray();
    } finally {
        $store->refuseRemoval = false;
        Cache::purge('removal-fixture');
    }
});

it('keeps general cache removal best-effort when deletion fails or a writer repopulates the key', function (bool $reportedSuccess, bool $repopulate): void {
    $store = new RefusesCacheRemovalStore($reportedSuccess, false, $repopulate);
    Cache::extend('removal-fixture', fn (): Repository => new Repository($store));
    config(['cache.default' => 'removal-fixture', 'cache.stores.removal-fixture.driver' => 'removal-fixture']);
    CapellCore::setToCache('ordinary-key', ['stale']);
    $store->refuseRemoval = true;

    try {
        CapellCore::removeCacheKey('ordinary-key');
        expect(CapellCore::getFromCache('ordinary-key'))->toBe($repopulate ? ['fresh'] : ['stale']);
    } finally {
        $store->refuseRemoval = false;
        Cache::purge('removal-fixture');
    }
})->with(['refused deletion' => [false, false], 'concurrent writer' => [true, true], 'refused with concurrent writer' => [false, true]]);

it('accepts a successful lifecycle removal followed by concurrent repopulation', function (): void {
    $store = new RefusesCacheRemovalStore(true, true, true);
    Cache::extend('removal-fixture', fn (): Repository => new Repository($store));
    config(['cache.default' => 'removal-fixture', 'cache.stores.removal-fixture.driver' => 'removal-fixture']);
    CapellCore::setToCache('lifecycle-key', ['stale']);
    $store->refuseRemoval = true;

    try {
        resolve(CapellCacheManager::class)->removeCacheKeyOrFail('lifecycle-key');
        expect(CapellCore::getFromCache('lifecycle-key'))->toBe(['fresh']);
    } finally {
        $store->refuseRemoval = false;
        Cache::purge('removal-fixture');
    }
});

it('keeps ordinary extension invalidation best-effort', function (): void {
    $store = new RefusesCacheRemovalStore(false, false);
    Cache::extend('removal-fixture', fn (): Repository => new Repository($store));
    config(['cache.default' => 'removal-fixture', 'cache.stores.removal-fixture.driver' => 'removal-fixture']);
    CapellCore::setToCache(CacheEnum::ExtensionInstalledNames->value, ['stale']);
    $store->refuseRemoval = true;

    try {
        CapellCore::clearExtensionCache();
        expect(CapellCore::getFromCache(CacheEnum::ExtensionInstalledNames->value))->toBe(['stale']);
    } finally {
        $store->refuseRemoval = false;
        Cache::purge('removal-fixture');
    }
});

it('refuses install enable and refresh when an existing lifecycle key cannot be removed', function (string $operation, string $key): void {
    CapellCore::registerPackage('test/cache-removal-activation');
    CapellCore::forcePackageInstalled('test/cache-removal-activation', false);
    $package = CapellCore::getPackage('test/cache-removal-activation');
    if ($operation === 'refresh') {
        CapellCore::markPackageInstalled($package->name);
    } elseif ($operation === 'enable') {
        CapellCore::markPackageDisabled($package->name);
    }

    $store = new RefusesCacheRemovalStore(false, false);
    Cache::extend('removal-fixture', fn (): Repository => new Repository($store));
    config(['cache.default' => 'removal-fixture', 'cache.stores.removal-fixture.driver' => 'removal-fixture']);
    CapellCore::setToCache($key, $key === CacheEnum::ExtensionInstalledNames->value ? [$package->name] : []);
    $store->refuseRemoval = true;

    try {
        expect(fn () => match ($operation) {
            'install' => InstallPackageAction::run($package),
            'enable' => EnablePackageAction::run($package),
            'refresh' => RefreshInstalledPackageRuntimeAction::run($package),
            default => throw new LogicException('Unknown runtime operation.'),
        })->toThrow(RuntimeException::class, __('capell::runtime-refresh.cache_refresh_required'));
    } finally {
        $store->refuseRemoval = false;
        Cache::purge('removal-fixture');
    }
})->with(['install', 'enable', 'refresh'])
    ->with([CacheEnum::ExtensionInstalledNames->value, CacheEnum::ExtensionPackages->value]);
