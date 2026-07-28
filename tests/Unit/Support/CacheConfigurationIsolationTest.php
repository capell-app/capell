<?php

declare(strict_types=1);

use Capell\Core\Support\Cache\CapellCacheManager;
use Illuminate\Cache\ArrayStore;
use Illuminate\Support\Facades\Cache;

it('allows a test to resolve a non-default cache service', function (): void {
    config([
        'cache.default' => 'database',
        'cache.stores.database.table' => 'missing_cache_configuration_isolation_table',
    ]);

    Cache::store();
    resolve(CapellCacheManager::class)->setToCache('cache-configuration-isolation', 'temporary');

    expect(config('cache.default'))->toBe('database');
});

it('restores cache configuration and resolved services for the next test', function (): void {
    expect(config('cache.default'))->toBe('array')
        ->and(Cache::store()->getStore())->toBeInstanceOf(ArrayStore::class);

    $cache = resolve(CapellCacheManager::class);
    $cache->setToCache('cache-configuration-isolation', 'restored');
    $cache->flushLocalCache();

    expect($cache->getFromCache('cache-configuration-isolation'))->toBe('restored');
});
