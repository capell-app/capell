<?php

declare(strict_types=1);

use Capell\Core\Enums\AssetComponentEnum;
use Capell\Core\Enums\CacheEnum;
use Capell\Core\Support\Cache\CapellCacheManager;
use Capell\Core\Support\CapellCoreManager;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\Store;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;

afterEach(function (): void {
    config([
        'cache.default' => 'array',
        'capell.disable_cache' => false,
        'capell.disable_cache_save_keys' => [],
        'capell.cache_tag' => 'capell-app',
        'capell.cache_lock_wait_seconds' => 10,
        'capell.cache_path' => base_path('bootstrap/cache/capell'),
    ]);

    Cache::flush();
});

it('enables the core cache by default', function (): void {
    $config = require dirname(__DIR__, 3) . '/config/capell.php';

    expect($config['disable_cache'])->toBeFalse();
});

it('reuses a persisted cache value after the request cache is flushed', function (): void {
    config([
        'cache.default' => 'array',
        'capell.disable_cache' => false,
    ]);

    $manager = resolve(CapellCacheManager::class);
    $callbackRuns = 0;

    $first = $manager->rememberCache('cache-hit-contract', function () use (&$callbackRuns): string {
        $callbackRuns++;

        return 'cached-value';
    });

    $manager->flushLocalCache();

    $second = $manager->rememberCache('cache-hit-contract', function () use (&$callbackRuns): string {
        $callbackRuns++;

        return 'unexpected-miss';
    });

    expect($first)->toBe('cached-value')
        ->and($second)->toBe('cached-value')
        ->and($callbackRuns)->toBe(1);
});

it('uses an atomic lock for a cold cache fill', function (): void {
    config([
        'cache.default' => 'array',
        'capell.disable_cache' => false,
    ]);
    $cache = Cache::spy();

    $value = resolve(CapellCacheManager::class)
        ->rememberCache('single-fill-contract', fn (): string => 'filled');

    expect($value)->toBe('filled');

    $cache->shouldHaveReceived('lock')
        ->once()
        ->with(
            Mockery::on(static fn (string $key): bool => str_starts_with($key, 'capell.cache.remember.')),
            30,
        );
});

it('waits through a contended lock lease before filling', function (): void {
    config([
        'cache.default' => 'array',
        'capell.cache_lock_seconds' => 2,
        'capell.cache_lock_wait_seconds' => 1,
    ]);

    $manager = resolve(CapellCacheManager::class);
    $normalizeCacheKey = new ReflectionMethod($manager, 'normalizeCacheKey');
    $lock = Cache::lock('capell.cache.remember.' . $normalizeCacheKey->invoke($manager, 'contended-fill'), 2);
    $lock->get();

    $callbackRuns = 0;

    try {
        $value = $manager->rememberCache('contended-fill', function () use (&$callbackRuns): string {
            $callbackRuns++;

            return 'filled-after-lease';
        });

        expect($value)->toBe('filled-after-lease')
            ->and($callbackRuns)->toBe(1);
    } finally {
        $lock->release();
    }
});

it('reports safe runtime cache activity and backend reachability', function (): void {
    config([
        'cache.default' => 'array',
        'capell.disable_cache' => false,
    ]);

    $manager = resolve(CapellCacheManager::class);

    $manager->rememberCache('customer:alice@example.test', fn (): string => 'cached');
    $manager->flushLocalCache();
    $manager->rememberCache('customer:alice@example.test', fn (): string => 'unexpected');

    $diagnostics = $manager->runtimeDiagnostics();

    expect($diagnostics->enabled)->toBeTrue()
        ->and($diagnostics->backendReachable)->toBeTrue()
        ->and($diagnostics->store)->toBe('array')
        ->and($diagnostics->hitCount)->toBeGreaterThanOrEqual(1)
        ->and($diagnostics->missCount)->toBeGreaterThanOrEqual(1)
        ->and($diagnostics->sampledKeyHashes)->not->toContain('customer:alice@example.test')
        ->and($diagnostics->sampledKeyHashes)->each->toMatch('/^[a-f0-9]{16}$/');

    $manager->flushRuntimeState();
    $persistedDiagnostics = $manager->runtimeDiagnostics();

    expect($persistedDiagnostics->hitCount)->toBeGreaterThanOrEqual(1)
        ->and($persistedDiagnostics->missCount)->toBeGreaterThanOrEqual(1)
        ->and($persistedDiagnostics->sampledKeyHashes)->not->toBeEmpty();
});

it('returns resolved values when the configured cache backend is unavailable', function (): void {
    $failingStore = new class implements Store
    {
        public function get($key): never
        {
            throw new RuntimeException('Cache backend unavailable.');
        }

        public function many(array $keys): never
        {
            throw new RuntimeException('Cache backend unavailable.');
        }

        public function put($key, $value, $seconds): never
        {
            throw new RuntimeException('Cache backend unavailable.');
        }

        public function putMany(array $values, $seconds): never
        {
            throw new RuntimeException('Cache backend unavailable.');
        }

        public function increment($key, $value = 1): never
        {
            throw new RuntimeException('Cache backend unavailable.');
        }

        public function decrement($key, $value = 1): never
        {
            throw new RuntimeException('Cache backend unavailable.');
        }

        public function forever($key, $value): never
        {
            throw new RuntimeException('Cache backend unavailable.');
        }

        public function touch($key, $seconds): never
        {
            throw new RuntimeException('Cache backend unavailable.');
        }

        public function forget($key): never
        {
            throw new RuntimeException('Cache backend unavailable.');
        }

        public function flush(): never
        {
            throw new RuntimeException('Cache backend unavailable.');
        }

        public function getPrefix(): string
        {
            return 'failing:';
        }
    };

    Cache::extend('failing', fn (): Repository => new Repository($failingStore));
    config([
        'cache.default' => 'failing',
        'cache.stores.failing.driver' => 'failing',
    ]);
    Cache::purge('failing');

    try {
        $manager = resolve(CapellCacheManager::class);
        $callbackRuns = 0;

        $first = $manager->rememberCache('backend-down', function () use (&$callbackRuns): string {
            $callbackRuns++;

            return 'resolved';
        });
        $second = $manager->rememberCache('backend-down', function () use (&$callbackRuns): string {
            $callbackRuns++;

            return 'unexpected';
        });
        $diagnostics = $manager->runtimeDiagnostics();

        expect($first)->toBe('resolved')
            ->and($second)->toBe('resolved')
            ->and($callbackRuns)->toBe(1)
            ->and($manager->getFromCache('missing-backend-key'))->toBeNull()
            ->and($diagnostics->backendReachable)->toBeFalse()
            ->and($diagnostics->backendFailureCount)->toBeGreaterThan(0);
    } finally {
        config(['cache.default' => 'array']);
        Cache::purge('failing');
    }
});

it('caches values, null sentinels, disabled saves, and cache increments through the core manager', function (): void {
    config([
        'cache.default' => 'array',
        'capell.disable_cache_save_keys' => [
            'draft.exact',
            'draft.wildcard.*',
            '/^draft\.regex\.\d+$/',
            '',
            42,
        ],
    ]);

    $manager = new CapellCoreManager;

    $callbackRuns = 0;
    $firstValue = $manager->rememberCache(CacheEnum::Site, function () use (&$callbackRuns): string {
        $callbackRuns++;

        return 'site payload';
    });
    $secondValue = $manager->rememberCache(CacheEnum::Site, function () use (&$callbackRuns): string {
        $callbackRuns++;

        return 'fresh payload';
    });

    $nullValue = $manager->rememberCache('nullable-key', fn (): null => null);
    $manager->flushLocalCache();

    expect($firstValue)->toBe('site payload')
        ->and($secondValue)->toBe('site payload')
        ->and($callbackRuns)->toBe(1)
        ->and($nullValue)->toBeNull()
        ->and($manager->getFromCache('nullable-key'))->toBeNull()
        ->and($manager->cacheExists('nullable-key'))->toBeFalse();

    $manager->setToCache('forever-key', 'persisted', ttl: 0);
    $manager->flushLocalCache();

    expect($manager->getFromCache('forever-key'))->toBe('persisted')
        ->and($manager->cacheExists('forever-key'))->toBeTrue()
        ->and($manager->incrementCacheKey('counter'))->toBe(1)
        ->and($manager->incrementCacheKey('counter'))->toBe(2);

    $manager->removeCacheKey('forever-key');

    expect($manager->cacheExists('forever-key'))->toBeFalse()
        ->and($manager->rememberCache('draft.exact', fn (): string => 'not saved'))->toBe('not saved')
        ->and($manager->rememberCache('draft.wildcard.preview', fn (): string => 'not saved'))->toBe('not saved')
        ->and($manager->rememberCache('draft.regex.123', fn (): string => 'not saved'))->toBe('not saved');

    $manager->setToCache('draft.exact', 'not saved through setter');

    $disabledSaveRuns = 0;
    $firstDisabledSave = $manager->rememberCache('draft.exact', function () use (&$disabledSaveRuns): string {
        $disabledSaveRuns++;

        return 'first uncached value';
    });
    $secondDisabledSave = $manager->rememberCache('draft.exact', function () use (&$disabledSaveRuns): string {
        $disabledSaveRuns++;

        return 'second uncached value';
    });

    $manager->flushLocalCache();

    expect($manager->getFromCache('draft.exact'))->toBeNull()
        ->and($manager->getFromCache('draft.wildcard.preview'))->toBeNull()
        ->and($manager->getFromCache('draft.regex.123'))->toBeNull()
        ->and($firstDisabledSave)->toBe('first uncached value')
        ->and($secondDisabledSave)->toBe('second uncached value')
        ->and($disabledSaveRuns)->toBe(2);

    config(['capell.disable_cache' => true]);

    $disabledRuns = 0;
    $manager->rememberCache('disabled-cache', function () use (&$disabledRuns): string {
        $disabledRuns++;

        return 'uncached';
    });
    $manager->rememberCache('disabled-cache', function () use (&$disabledRuns): string {
        $disabledRuns++;

        return 'uncached';
    });

    expect($disabledRuns)->toBe(2);
});

it('respects ttl callbacks and namespace bumps on cache stores without tag support', function (): void {
    $cachePath = storage_path('framework/testing/cache-store-' . uniqid());
    File::ensureDirectoryExists($cachePath);

    config([
        'cache.default' => 'file',
        'cache.stores.file.driver' => 'file',
        'cache.stores.file.path' => $cachePath,
    ]);

    $manager = new CapellCoreManager;

    try {
        $value = $manager->rememberCache('file-backed-key', fn (): string => 'stored', fn (): DateInterval => new DateInterval('PT60S'));
        $manager->flushLocalCache();

        expect($value)->toBe('stored')
            ->and($manager->rememberCache('file-backed-key', fn (): string => 'fresh'))->toBe('stored')
            ->and(request()->attributes->has('capell.cache.generation.capell-app'))->toBeTrue();

        $manager->flushCache();

        expect(Cache::store()->get('capell.cache.generation.capell-app'))->toBe(1)
            ->and($manager->rememberCache('file-backed-key', fn (): string => 'fresh'))->toBe('fresh');

    } finally {
        File::deleteDirectory($cachePath);
    }
});

it('falls back to in-memory cache storage when the configured database cache table is unavailable', function (): void {
    config([
        'cache.default' => 'database',
        'cache.stores.database.driver' => 'database',
        'cache.stores.database.table' => 'missing_cache_table_for_install',
    ]);

    $manager = new CapellCoreManager;

    expect($manager->rememberCache('database-cache-miss', fn (): string => 'fallback'))->toBe('fallback')
        ->and($manager->getFromCache('database-cache-miss'))->toBe('fallback');
});

it('discovers, restores, and clears cached frontend component registrations', function (): void {
    $root = storage_path('framework/testing/components-' . uniqid());

    File::ensureDirectoryExists($root . '/page-sections');
    File::ensureDirectoryExists($root . '/media_cards');
    File::put($root . '/page-sections/hero.blade.php', '<section>Hero</section>');
    File::put($root . '/page-sections/readme.txt', 'ignored');
    File::put($root . '/media_cards/tile.blade.php', '<article>Tile</article>');

    config(['capell.cache_path' => $root . '/cache']);

    $manager = new CapellCoreManager;
    $manager
        ->registerComponent(AssetComponentEnum::Card, AssetComponentEnum::Media, 'manual-card')
        ->registerComponent(AssetComponentEnum::Card, AssetComponentEnum::Media, 'ignored-duplicate')
        ->registerComponents('Widget', [
            'alpha' => 'widget-alpha',
            AssetComponentEnum::Tile,
            'invalid' => 123,
        ])
        ->registerDiscoverableComponents($root, 'public')
        ->discoverComponents('')
        ->registerDiscoverableComponents($root . '/missing-components');

    expect(CapellCoreManager::getComponentTypeFromDirectory($root . '/page-sections'))->toBe('PageSections')
        ->and($manager->hasCachedComponents())->toBeFalse()
        ->and($manager->getComponent(AssetComponentEnum::Card, AssetComponentEnum::Media->name))->toBe('manual-card')
        ->and($manager->getCoreComponents(AssetComponentEnum::Card))->toBe([
            AssetComponentEnum::Media->name => 'manual-card',
        ])
        ->and($manager->getComponents('Widget'))->toBe([
            AssetComponentEnum::Tile->name => AssetComponentEnum::Tile->value,
            'alpha' => 'widget-alpha',
        ])
        ->and($manager->getComponent('PageSections', 'public.hero'))->toBe('public.hero')
        ->and($manager->hasComponent('MediaCards', 'public.tile'))->toBeTrue()
        ->and($manager->getComponents())->toHaveKeys(['Card', 'MediaCards', 'PageSections', 'Widget']);

    $manager->cacheComponents();
    $manager->registerComponent('Widget', 'beta', 'widget-beta');
    $manager->discoverComponents($root);

    expect(File::exists($manager->getComponentCachePath()))->toBeTrue()
        ->and($manager->hasCachedComponents())->toBeTrue();

    $manager->restoreCachedComponents();

    expect($manager->hasComponent('Widget', 'beta'))->toBeFalse()
        ->and($manager->getComponent('PageSections', 'public.hero'))->toBe('public.hero');

    $manager->clearCachedComponents();

    expect(File::exists($manager->getComponentCachePath()))->toBeFalse();

    File::deleteDirectory($root);
});

it('throws a clear exception for unknown core and discovered components', function (): void {
    $manager = new CapellCoreManager;

    expect(fn (): string => $manager->getComponent('MissingType', 'missing'))
        ->toThrow(InvalidArgumentException::class, 'Component with type MissingType and name missing not found.');

    expect(fn (): array => $manager->getCoreComponents('MissingType'))
        ->toThrow(InvalidArgumentException::class, 'Component type MissingType not found.');
});
