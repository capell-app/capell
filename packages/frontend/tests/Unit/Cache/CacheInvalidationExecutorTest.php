<?php

declare(strict_types=1);

use Capell\Core\Models\Page;
use Capell\Frontend\Data\CacheInvalidationPlanData;
use Capell\Frontend\Data\CacheInvalidationRule;
use Capell\Frontend\Enums\CacheEnum;
use Capell\Frontend\Support\Cache\CacheInvalidationExecutor;
use Capell\Frontend\Support\Cache\PageListingCache;
use Capell\Frontend\Support\Cache\PageModelCache;
use Capell\Frontend\Support\Cache\PublicPageRenderDataCache;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;

it('executes explicit cache key invalidation rules', function (): void {
    $executor = resolve(CacheInvalidationExecutor::class);
    $executor->setToCache('custom-key', 'cached');

    $executor->execute(new CacheInvalidationPlanData([
        CacheInvalidationRule::forgetKey('custom-key'),
    ]));

    expect($executor->getFromCache('custom-key'))->toBeNull();
});

it('executes page model, listing, and public render data invalidation rules', function (): void {
    $pageModelCache = resolve(PageModelCache::class);
    $pageListingCache = resolve(PageListingCache::class);
    $publicRenderDataCache = resolve(PublicPageRenderDataCache::class);

    $pageModelCache->setToCache(CacheEnum::pageModel(Page::class, 10, 20, 30), 'cached');

    resolve(CacheInvalidationExecutor::class)->execute(new CacheInvalidationPlanData([
        CacheInvalidationRule::pageModel(Page::class, 10, 20, 30),
        CacheInvalidationRule::pageListing(20, 30),
        CacheInvalidationRule::publicRenderData(Page::class, 10, 20, 30),
    ]));

    expect($pageModelCache->getFromCache(CacheEnum::pageModel(Page::class, 10, 20, 30)))->toBeNull()
        ->and($pageListingCache->getFromCache(CacheEnum::listingGeneration(20, 30)))->toBe(1)
        ->and($publicRenderDataCache->getFromCache(CacheEnum::publicRenderDataGeneration(Page::class, 10, 20, 30)))->toBe(1);
});

it('flushes the frontend tag and resolved local cache state', function (): void {
    $cachePath = storage_path('framework/testing/cache-' . uniqid('', true));

    config()->set('cache.default', 'file');
    config()->set('cache.stores.file.path', $cachePath);
    Cache::purge('file');

    $pageModelCache = resolve(PageModelCache::class);
    $pageModelCache->setToCache(CacheEnum::pageModel(Page::class, 10, 20, 30), 'cached');

    $generationKey = 'capell.cache.generation.' . config('capell.cache_tag', 'capell-app');
    Cache::store()->forget($generationKey);

    try {
        resolve(CacheInvalidationExecutor::class)->execute(new CacheInvalidationPlanData([
            CacheInvalidationRule::flushFrontendTag(),
        ]));

        $generation = Cache::store()->get($generationKey);
        $cachedModel = $pageModelCache->getFromCache(CacheEnum::pageModel(Page::class, 10, 20, 30));
    } finally {
        Cache::purge('file');
        File::deleteDirectory($cachePath);
        config()->set('cache.default', 'array');
    }

    expect($generation)->toBe(1)
        ->and($cachedModel)->toBeNull();
});

it('is safe to execute the same targeted invalidation plan repeatedly', function (): void {
    $pageModelCache = resolve(PageModelCache::class);
    $publicRenderDataCache = resolve(PublicPageRenderDataCache::class);
    $modelKey = CacheEnum::pageModel(Page::class, 10, 20, 30);
    $generationKey = CacheEnum::publicRenderDataGeneration(Page::class, 10, 20, 30);
    $plan = new CacheInvalidationPlanData([
        CacheInvalidationRule::pageModel(Page::class, 10, 20, 30),
        CacheInvalidationRule::publicRenderData(Page::class, 10, 20, 30),
    ]);

    $pageModelCache->setToCache($modelKey, 'cached');
    $executor = resolve(CacheInvalidationExecutor::class);
    $executor->execute($plan);
    $executor->execute($plan);

    expect($pageModelCache->getFromCache($modelKey))->toBeNull()
        ->and($publicRenderDataCache->getFromCache($generationKey))->toBe(2);
});
