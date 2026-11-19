<?php

declare(strict_types=1);

use Capell\Core\Support\Cache\CacheDependencyRegistry;
use Capell\Core\Support\Cache\CapellCacheManager;
use Capell\Frontend\Data\CacheInvalidationRule;
use Capell\Frontend\Support\Cache\CacheInvalidationDependencyRegistry;
use Capell\Frontend\Support\Cache\CacheInvalidationExecutor;
use Capell\Frontend\Support\Cache\CacheInvalidationRegistry;
use Capell\Frontend\Tests\Fixtures\ScheduledPublicationEntry;

it('clears cached output a package declared through Core when its model changes', function (): void {
    // Frontend's invalidation is already resolved for this request, as it is when
    // a package boots late (installation) or declares after the first change.
    $invalidation = resolve(CacheInvalidationRegistry::class);
    $executor = resolve(CacheInvalidationExecutor::class);

    resolve(CacheDependencyRegistry::class)->declare(ScheduledPublicationEntry::class, ['scheduled-entries-index', 'scheduled-entry-card-*']);

    $executor->setToCache('scheduled-entries-index', 'stale index');
    $executor->setToCache('scheduled-entry-card-7', 'stale card');
    $executor->setToCache('unrelated-feature', 'keep-me');
    $executor->flushLocalCache();

    $invalidation->invalidateChangedModel((new ScheduledPublicationEntry)->forceFill(['id' => 7, 'site_id' => 1]));
    $executor->flushLocalCache();

    expect($executor->getFromCache('scheduled-entries-index'))->toBeNull()
        ->and($executor->getFromCache('scheduled-entry-card-7'))->toBeNull()
        ->and($executor->getFromCache('unrelated-feature'))->toBe('keep-me');
});

it('keeps Frontend registrations and Core declarations for one model together', function (): void {
    resolve(CacheInvalidationRegistry::class)->registerDependency(ScheduledPublicationEntry::class, 'scheduled-entries-feed');
    resolve(CacheDependencyRegistry::class)->declare(ScheduledPublicationEntry::class, 'scheduled-entries-index');

    $plannedKeys = array_map(
        static fn (CacheInvalidationRule $rule): ?string => $rule->cacheKey,
        resolve(CacheInvalidationRegistry::class)->planForModel(ScheduledPublicationEntry::class)->rules,
    );

    expect($plannedKeys)->toBe(['scheduled-entries-feed', 'scheduled-entries-index'])
        ->and(resolve(CacheDependencyRegistry::class)->patternsFor(ScheduledPublicationEntry::class))
        ->toBe(['scheduled-entries-feed', 'scheduled-entries-index']);
});

it('keeps a declared pattern retired in a later request that only reads the cache', function (): void {
    // Each closure boots a fresh process: new singletons, and the package
    // declaring its dependency again from its service provider.
    $bootProcess = function (): CacheInvalidationExecutor {
        app()->forgetInstance(CapellCacheManager::class);
        app()->forgetInstance(CacheDependencyRegistry::class);
        app()->forgetInstance(CacheInvalidationDependencyRegistry::class);
        app()->forgetScopedInstances();

        resolve(CacheDependencyRegistry::class)->declare(ScheduledPublicationEntry::class, 'scheduled-entry-card-*');

        return resolve(CacheInvalidationExecutor::class);
    };

    $bootProcess()->setToCache('scheduled-entry-card-7', 'stale card');

    $bootProcess();
    resolve(CacheInvalidationRegistry::class)->invalidateForModel(ScheduledPublicationEntry::class);

    // A render-only request never resolves Frontend's invalidation registry.
    expect($bootProcess()->getFromCache('scheduled-entry-card-7'))->toBeNull();
});
