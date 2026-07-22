<?php

declare(strict_types=1);

use Capell\Core\Enums\MediaCollectionEnum;
use Capell\Core\Models\Media;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Frontend\Data\CacheInvalidationRule;
use Capell\Frontend\Support\Cache\CacheInvalidationDependencyRegistry;
use Capell\Frontend\Support\Cache\CacheInvalidationExecutor;
use Capell\Frontend\Support\Cache\CacheInvalidationRegistry;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;

it('plans scoped pattern invalidation for wildcard model dependencies', function (): void {
    $plan = resolve(CacheInvalidationRegistry::class)->planForModel(Page::class);

    expect(collect($plan->rules)->contains(
        fn (CacheInvalidationRule $rule): bool => $rule->kind === CacheInvalidationRule::KIND_INVALIDATE_PATTERN
            && $rule->cacheKey === 'page-*',
    ))->toBeTrue()
        ->and(collect($plan->rules)->contains(
            fn (CacheInvalidationRule $rule): bool => $rule->kind === CacheInvalidationRule::KIND_FLUSH_FRONTEND_TAG,
        ))->toBeFalse();
});

it('registers custom key dependencies and forgets those cache entries', function (): void {
    $registry = resolve(CacheInvalidationRegistry::class);
    $executor = resolve(CacheInvalidationExecutor::class);
    $registry->registerDependency('Vendor\\Package\\Model', ['custom-key', 'another-key']);

    $executor->setToCache('custom-key', 'cached');
    $executor->setToCache('another-key', 'cached');

    $plan = $registry->planForModel('Vendor\\Package\\Model');

    expect($plan->rules)->toHaveCount(2)
        ->and($plan->rules[0]->kind)->toBe(CacheInvalidationRule::KIND_FORGET_KEY)
        ->and($plan->rules[0]->cacheKey)->toBe('custom-key');

    $registry->invalidateForModel('Vendor\\Package\\Model');

    expect($executor->getFromCache('custom-key'))->toBeNull()
        ->and($executor->getFromCache('another-key'))->toBeNull();
});

it('keeps boot dependency registrations across scoped lifecycle resets', function (): void {
    $dependencies = resolve(CacheInvalidationDependencyRegistry::class);
    $registry = resolve(CacheInvalidationRegistry::class);
    $registry->registerDependency('Vendor\\Package\\PersistentModel', 'persistent-key');

    app()->forgetScopedInstances();

    $nextDependencies = resolve(CacheInvalidationDependencyRegistry::class);
    $nextRegistry = resolve(CacheInvalidationRegistry::class);
    $nextExecutor = resolve(CacheInvalidationExecutor::class);
    $nextExecutor->setToCache('persistent-key', 'cached');

    $plan = $nextRegistry->planForModel('Vendor\\Package\\PersistentModel');
    $nextRegistry->invalidateForModel('Vendor\\Package\\PersistentModel');

    expect($nextDependencies)->toBe($dependencies)
        ->and($nextRegistry)->not->toBe($registry)
        ->and($plan->rules)->toHaveCount(1)
        ->and($plan->rules[0]->kind)->toBe(CacheInvalidationRule::KIND_FORGET_KEY)
        ->and($plan->rules[0]->cacheKey)->toBe('persistent-key')
        ->and($nextExecutor->getFromCache('persistent-key'))->toBeNull();
});

it('does not flush unrelated application cache entries for wildcard frontend dependencies', function (): void {
    config()->set('cache.default', 'array');

    Cache::put('unrelated-application-key', 'keep-me');

    resolve(CacheInvalidationRegistry::class)->invalidateForModel(Page::class);

    expect(Cache::get('unrelated-application-key'))->toBe('keep-me');
});

it('invalidates only cache keys matching a wildcard dependency', function (): void {
    $registry = resolve(CacheInvalidationRegistry::class);
    $executor = resolve(CacheInvalidationExecutor::class);

    $executor->setToCache('page-123', 'stale-page');
    $executor->setToCache('unrelated-feature', 'keep-me');
    $executor->flushLocalCache();

    $registry->invalidateForModel(Page::class);

    expect($executor->getFromCache('page-123'))->toBeNull()
        ->and($executor->getFromCache('unrelated-feature'))->toBe('keep-me');
});

it('uses scoped site and page invalidation for site logo media changes', function (): void {
    $site = Site::factory()->create();
    $media = Media::factory()
        ->model($site)
        ->collection(MediaCollectionEnum::Logo)
        ->create();

    $plan = resolve(CacheInvalidationRegistry::class)->planForChangedModel($media);

    expect(collect($plan->rules)->contains(
        fn (CacheInvalidationRule $rule): bool => $rule->kind === CacheInvalidationRule::KIND_INVALIDATE_PATTERN,
    ))->toBeTrue()
        ->and(collect($plan->rules)->contains(
            fn (CacheInvalidationRule $rule): bool => $rule->kind === CacheInvalidationRule::KIND_FLUSH_FRONTEND_TAG,
        ))->toBeFalse();
});

it('does not flush frontend cache for unrelated media changes', function (): void {
    $site = Site::factory()->create();
    $media = Media::factory()
        ->model($site)
        ->collection(MediaCollectionEnum::Image)
        ->create();

    $plan = resolve(CacheInvalidationRegistry::class)->planForChangedModel($media);

    expect($plan->rules)->toBe([]);
});

it('bumps the frontend generation for wildcard dependencies on non-atomic cache stores', function (): void {
    $cachePath = storage_path('framework/testing/cache-' . uniqid('', true));

    config()->set('cache.default', 'file');
    config()->set('cache.stores.file.path', $cachePath);
    Cache::purge('file');

    $generationKey = 'capell.cache.pattern-generation.' . hash('sha256', 'page-*');
    Cache::store()->forget($generationKey);

    try {
        resolve(CacheInvalidationRegistry::class)->invalidateForModel(Page::class);
        $firstGeneration = Cache::store()->get($generationKey);

        resolve(CacheInvalidationRegistry::class)->invalidateForModel(Page::class);
        $secondGeneration = Cache::store()->get($generationKey);
    } finally {
        Cache::purge('file');
        File::deleteDirectory($cachePath);
        config()->set('cache.default', 'array');
    }

    expect($firstGeneration)->toBe(1)
        ->and($secondGeneration)->toBe(2);
});
