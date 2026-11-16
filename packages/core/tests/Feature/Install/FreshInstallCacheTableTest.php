<?php

declare(strict_types=1);

use Capell\Core\Data\PackageData;
use Capell\Core\Enums\PackageRole;
use Capell\Core\Support\Cache\ResilientDataStructureCache;
use Capell\Core\Support\Packages\PackageRoleResolver;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\Factory;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\LaravelData\Support\Caching\CachedDataConfig;
use Spatie\LaravelData\Support\Caching\DataStructureCache;
use Spatie\LaravelData\Support\DataConfig;

/**
 * A fresh application caches in the database and has no `cache` table until its migrations run,
 * which is after `capell:install` has already read package roles and manifest metadata.
 */
beforeEach(function (): void {
    $this->cacheDatabasePath = tempnam(sys_get_temp_dir(), 'capell-fresh-cache-');

    config([
        'database.connections.fresh_cache' => [
            'driver' => 'sqlite',
            'database' => $this->cacheDatabasePath,
            'prefix' => '',
            'foreign_key_constraints' => true,
        ],
        'cache.default' => 'database',
        'cache.stores.database.driver' => 'database',
        'cache.stores.database.connection' => 'fresh_cache',
        'cache.stores.database.lock_connection' => 'fresh_cache',
        'cache.stores.database.table' => 'cache',
        'data.structure_caching.enabled' => true,
        'data.structure_caching.cache.store' => 'database',
    ]);

    // Spatie skips its structure cache while the application reports it is running unit tests.
    app()->detectEnvironment(fn (): string => 'production');

    foreach ([DataConfig::class, DataStructureCache::class, 'cache', 'cache.store'] as $abstract) {
        app()->forgetInstance($abstract);
    }

    DB::purge('fresh_cache');
});

afterEach(function (): void {
    DB::purge('fresh_cache');

    if (is_string($this->cacheDatabasePath) && is_file($this->cacheDatabasePath)) {
        unlink($this->cacheDatabasePath);
    }
});

it('starts from a database with no cache table', function (): void {
    expect(Schema::connection('fresh_cache')->hasTable('cache'))->toBeFalse()
        ->and(app()->runningUnitTests())->toBeFalse();
});

it('reads package roles before the cache table exists', function (): void {
    expect(fn (): ?PackageData => resolve(PackageRoleResolver::class)->packageWithRole(PackageRole::DeveloperTooling))
        ->not->toThrow(QueryException::class);
});

it('builds Data objects before the cache table exists', function (): void {
    $packages = PackageData::collect([]);

    expect($packages)->toHaveCount(0)
        ->and(resolve(DataConfig::class))->toBeInstanceOf(DataConfig::class)
        ->and(resolve(DataStructureCache::class))->toBeInstanceOf(ResilientDataStructureCache::class);
});

it('stops reading an unavailable cache backend once it has failed', function (): void {
    $store = new class extends ArrayStore
    {
        public int $reads = 0;

        #[Override]
        public function get($key): never
        {
            $this->reads++;

            throw new QueryException('fresh_cache', 'select * from "cache"', [], new RuntimeException('no such table: cache'));
        }
    };
    resolve(Factory::class)->extend('missing-table', fn (): Repository => new Repository($store));
    config([
        'cache.stores.missing-table' => ['driver' => 'missing-table'],
        'data.structure_caching.cache.store' => 'missing-table',
    ]);
    app()->forgetInstance(DataStructureCache::class);

    $cache = resolve(DataStructureCache::class);

    expect($cache->getConfig())->toBeNull()
        ->and($cache->getDataClass(PackageData::class))->toBeNull()
        ->and($cache->getDataClass(PackageData::class))->toBeNull()
        ->and($store->reads)->toBe(1);
});

it('prints a fresh install plan before the cache table exists', function (): void {
    $exitCode = Artisan::call('capell:install', [
        '--plan' => true,
        '--package-mode' => 'core',
        '--url' => 'https://example.test',
        '--name' => 'Capell Admin',
        '--email' => 'admin@example.test',
        '--password' => 'a-long-test-password-1',
        '--no-boost-install' => true,
        '--no-interaction' => true,
    ]);

    expect($exitCode)->toBe(0)
        ->and(Artisan::output())->toContain('Capell Install Plan');
});

it('still stores and reads Data structures once the cache table exists', function (): void {
    Schema::connection('fresh_cache')->create('cache', function (Blueprint $table): void {
        $table->string('key')->primary();
        $table->mediumText('value');
        $table->integer('expiration');
    });

    $cache = resolve(DataStructureCache::class);

    $cache->storeConfig(CachedDataConfig::createFromConfig(config('data')));

    expect(DB::connection('fresh_cache')->table('cache')->count())->toBe(1)
        ->and($cache->getConfig())->toBeInstanceOf(CachedDataConfig::class);
});
