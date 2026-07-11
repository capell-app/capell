<?php

declare(strict_types=1);

use Capell\Core\Enums\AssetComponentEnum;
use Capell\Core\Enums\CacheEnum;
use Capell\Core\Support\CapellCoreManager;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;

afterEach(function (): void {
    config([
        'cache.default' => 'array',
        'capell-core.disable_cache' => false,
        'capell-core.disable_cache_save_keys' => [],
        'capell-core.cache_tag' => 'capell-app',
        'capell.cache_path' => base_path('bootstrap/cache/capell'),
    ]);

    Cache::flush();
});

it('caches values, null sentinels, disabled saves, and cache increments through the core manager', function (): void {
    config([
        'cache.default' => 'array',
        'capell-core.disable_cache_save_keys' => [
            'draft.exact',
            'draft.wildcard.*',
            '/^draft\.regex\.\d+$/',
            '',
            42,
        ],
    ]);

    $manager = new class extends CapellCoreManager
    {
        public function incrementPublicCacheKey(string $key): int
        {
            return $this->incrementCacheKey($key);
        }

        public function incrementPublicRawCacheKey(string $key): int
        {
            return $this->incrementRawCacheKey($key);
        }
    };

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
        ->and($manager->incrementPublicCacheKey('counter'))->toBe(1)
        ->and($manager->incrementPublicCacheKey('counter'))->toBe(2)
        ->and($manager->incrementPublicRawCacheKey('raw-counter'))->toBe(1);

    $manager->removeCacheKey('forever-key');

    expect($manager->cacheExists('forever-key'))->toBeFalse()
        ->and($manager->rememberCache('draft.exact', fn (): string => 'not saved'))->toBe('not saved')
        ->and($manager->rememberCache('draft.wildcard.preview', fn (): string => 'not saved'))->toBe('not saved')
        ->and($manager->rememberCache('draft.regex.123', fn (): string => 'not saved'))->toBe('not saved');

    $manager->setToCache('draft.exact', 'not saved through setter');

    $manager->flushLocalCache();

    expect($manager->getFromCache('draft.exact'))->toBeNull()
        ->and($manager->getFromCache('draft.wildcard.preview'))->toBeNull()
        ->and($manager->getFromCache('draft.regex.123'))->toBeNull();

    config(['capell-core.disable_cache' => true]);

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

    $manager = new class extends CapellCoreManager
    {
        public function configuredStoreIsAvailable(): bool
        {
            return $this->configuredCacheStoreIsAvailable();
        }
    };

    try {
        $value = $manager->rememberCache('file-backed-key', fn (): string => 'stored', fn (): DateInterval => new DateInterval('PT60S'));
        $manager->flushLocalCache();

        expect($value)->toBe('stored')
            ->and($manager->rememberCache('file-backed-key', fn (): string => 'fresh'))->toBe('stored')
            ->and(request()->attributes->has('capell.cache.generation.capell-app'))->toBeTrue();

        $manager->flushCache();

        expect(Cache::store()->get('capell.cache.generation.capell-app'))->toBe(1)
            ->and($manager->rememberCache('file-backed-key', fn (): string => 'fresh'))->toBe('fresh');

        config(['cache.default' => '']);
        expect($manager->configuredStoreIsAvailable())->toBeTrue();

        config([
            'cache.default' => 'database',
            'cache.stores.database.driver' => 'database',
            'cache.stores.database.table' => '',
        ]);
        expect($manager->configuredStoreIsAvailable())->toBeTrue();
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
