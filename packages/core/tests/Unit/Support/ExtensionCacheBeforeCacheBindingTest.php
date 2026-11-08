<?php

declare(strict_types=1);

use Capell\Core\Enums\CacheEnum;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Support\PackageRegistry\CapellPackageRegistry;
use Illuminate\Contracts\Cache\Factory;
use Illuminate\Support\Facades\Cache;

it('quarantines a provider before the cache binding exists and forgets stale extension keys once the cache resolves', function (): void {
    CapellCore::setToCache(CacheEnum::ExtensionPackages->value, ['stale']);
    CapellCore::setToCache(CacheEnum::ExtensionInstalledNames->value, ['stale']);

    $cache = resolve(Factory::class);
    app()->offsetUnset('cache');
    Cache::clearResolvedInstance('cache');

    try {
        resolve(CapellPackageRegistry::class)->markPackageProviderQuarantined(
            name: 'capell-app/core',
            provider: 'Vendor\\Broken\\BrokenServiceProvider',
            reason: 'Provider failed during registration.',
        );
    } finally {
        app()->singleton('cache', fn (): object => $cache);
        Cache::clearResolvedInstance('cache');
    }

    resolve(Factory::class);

    expect(CapellCore::cacheExists(CacheEnum::ExtensionPackages->value))->toBeFalse()
        ->and(CapellCore::cacheExists(CacheEnum::ExtensionInstalledNames->value))->toBeFalse();
});
