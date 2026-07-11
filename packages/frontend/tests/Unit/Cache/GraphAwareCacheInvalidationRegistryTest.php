<?php

declare(strict_types=1);

use Capell\Core\Enums\ContentGraph\ContentGraphEdgeKind;
use Capell\Core\Enums\ContentGraph\ContentGraphEdgeStrength;
use Capell\Core\Models\ContentGraphEdge;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Page;
use Capell\Core\Models\Translation;
use Capell\Frontend\Data\CacheInvalidationRule;
use Capell\Frontend\Support\Cache\CacheInvalidationRegistry;

it('plans page cache invalidation from graph dependents when target model changes', function (): void {
    $layout = Layout::factory()->createOne();
    $page = Page::factory()
        ->withTranslations()
        ->create(['layout_id' => $layout->id]);

    ContentGraphEdge::query()->create([
        'source_type' => Page::class,
        'source_id' => $page->id,
        'target_type' => Layout::class,
        'target_id' => $layout->id,
        'kind' => ContentGraphEdgeKind::UsesLayout,
        'strength' => ContentGraphEdgeStrength::Strong,
        'source_package' => 'capell-app/core',
        'site_id' => $page->site_id,
    ]);

    $plan = resolve(CacheInvalidationRegistry::class)->planForChangedModel($layout);

    expect($plan->rules)->not->toBeEmpty()
        ->and(collect($plan->rules)->contains(
            fn (CacheInvalidationRule $rule): bool => $rule->kind === CacheInvalidationRule::KIND_FLUSH_FRONTEND_TAG,
        ))->toBeFalse()
        ->and(collect($plan->rules)->contains(
            fn (CacheInvalidationRule $rule): bool => $rule->kind === CacheInvalidationRule::KIND_PAGE_MODEL
                && $rule->modelType === Page::class
                && $rule->modelId === $page->id,
        ))->toBeTrue()
        ->and(collect($plan->rules)->contains(
            fn (CacheInvalidationRule $rule): bool => $rule->kind === CacheInvalidationRule::KIND_PUBLIC_RENDER_DATA
                && $rule->modelType === Page::class
                && $rule->modelId === $page->id,
        ))->toBeTrue();
});

it('plans exact page cache invalidation for changed pages without falling back to broad frontend flushes', function (): void {
    $page = Page::factory()
        ->withTranslations()
        ->create();

    $plan = resolve(CacheInvalidationRegistry::class)->planForChangedModel($page);

    expect($plan->rules)->not->toBeEmpty()
        ->and(collect($plan->rules)->contains(
            fn (CacheInvalidationRule $rule): bool => $rule->kind === CacheInvalidationRule::KIND_FLUSH_FRONTEND_TAG,
        ))->toBeFalse()
        ->and(collect($plan->rules)->contains(
            fn (CacheInvalidationRule $rule): bool => $rule->kind === CacheInvalidationRule::KIND_PAGE_MODEL
                && $rule->modelType === Page::class
                && $rule->modelId === $page->id,
        ))->toBeTrue();
});

it('plans exact cache invalidation for changed pages and pages depending on them', function (): void {
    $changedPage = Page::factory()
        ->withTranslations()
        ->create();
    $dependentPage = Page::factory()
        ->recycle($changedPage->site)
        ->withTranslations()
        ->create();

    ContentGraphEdge::query()->create([
        'source_type' => Page::class,
        'source_id' => $dependentPage->id,
        'target_type' => Page::class,
        'target_id' => $changedPage->id,
        'kind' => ContentGraphEdgeKind::RelatesToPage,
        'strength' => ContentGraphEdgeStrength::Strong,
        'source_package' => 'capell-app/core',
        'site_id' => $changedPage->site_id,
    ]);

    $plan = resolve(CacheInvalidationRegistry::class)->planForChangedModel($changedPage);

    expect(collect($plan->rules)->contains(
        fn (CacheInvalidationRule $rule): bool => $rule->kind === CacheInvalidationRule::KIND_FLUSH_FRONTEND_TAG,
    ))->toBeFalse()
        ->and(collect($plan->rules)->contains(
            fn (CacheInvalidationRule $rule): bool => $rule->kind === CacheInvalidationRule::KIND_PAGE_MODEL
                && $rule->modelType === Page::class
                && $rule->modelId === $changedPage->id,
        ))->toBeTrue()
        ->and(collect($plan->rules)->contains(
            fn (CacheInvalidationRule $rule): bool => $rule->kind === CacheInvalidationRule::KIND_PUBLIC_RENDER_DATA
                && $rule->modelType === Page::class
                && $rule->modelId === $changedPage->id,
        ))->toBeTrue()
        ->and(collect($plan->rules)->contains(
            fn (CacheInvalidationRule $rule): bool => $rule->kind === CacheInvalidationRule::KIND_PAGE_MODEL
                && $rule->modelType === Page::class
                && $rule->modelId === $dependentPage->id,
        ))->toBeTrue()
        ->and(collect($plan->rules)->contains(
            fn (CacheInvalidationRule $rule): bool => $rule->kind === CacheInvalidationRule::KIND_PUBLIC_RENDER_DATA
                && $rule->modelType === Page::class
                && $rule->modelId === $dependentPage->id,
        ))->toBeTrue();
});

it('keeps registered class rules when graph dependents also exist', function (): void {
    $registry = resolve(CacheInvalidationRegistry::class);
    $layout = Layout::factory()->createOne();
    $page = Page::factory()
        ->withTranslations()
        ->create(['layout_id' => $layout->id]);

    $registry->registerDependency(Layout::class, 'layout-index');

    ContentGraphEdge::query()->create([
        'source_type' => Page::class,
        'source_id' => $page->id,
        'target_type' => Layout::class,
        'target_id' => $layout->id,
        'kind' => ContentGraphEdgeKind::UsesLayout,
        'strength' => ContentGraphEdgeStrength::Strong,
        'source_package' => 'capell-app/core',
        'site_id' => $page->site_id,
    ]);

    $plan = $registry->planForChangedModel($layout);

    expect(collect($plan->rules)->contains(
        fn (CacheInvalidationRule $rule): bool => $rule->kind === CacheInvalidationRule::KIND_FORGET_KEY
            && $rule->cacheKey === 'layout-index',
    ))->toBeTrue()
        ->and(collect($plan->rules)->contains(
            fn (CacheInvalidationRule $rule): bool => $rule->kind === CacheInvalidationRule::KIND_PAGE_MODEL
                && $rule->modelType === Page::class
                && $rule->modelId === $page->id,
        ))->toBeTrue();
});

it('invalidates page render caches when a page translation changes', function (): void {
    $page = Page::factory()
        ->withTranslations()
        ->create();
    $translation = $page->translations->first();

    expect($translation)->toBeInstanceOf(Translation::class);

    $plan = resolve(CacheInvalidationRegistry::class)->planForChangedModel($translation);

    expect(collect($plan->rules)->contains(
        fn (CacheInvalidationRule $rule): bool => $rule->kind === CacheInvalidationRule::KIND_PUBLIC_RENDER_DATA
            && $rule->modelType === Page::class
            && $rule->modelId === $page->id,
    ))->toBeTrue();
});
