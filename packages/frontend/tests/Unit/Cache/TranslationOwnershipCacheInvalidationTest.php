<?php

declare(strict_types=1);

use Capell\Core\Enums\ContentGraph\ContentGraphEdgeKind;
use Capell\Core\Enums\ContentGraph\ContentGraphEdgeStrength;
use Capell\Core\Models\ContentGraphEdge;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Media;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Models\Translation;
use Capell\Frontend\Data\CacheInvalidationRule;
use Capell\Frontend\Support\Cache\CacheInvalidationRegistry;

it('resolves a pageable translation to its owning page', function (): void {
    $page = Page::factory()->withTranslations()->create();
    $translation = $page->translations->firstOrFail();
    $translation->unsetRelation('translatable');

    $plan = resolve(CacheInvalidationRegistry::class)->planForChangedModel($translation);

    expect(planContainsPage($plan->rules, $page))->toBeTrue()
        ->and(planContainsFlush($plan->rules))->toBeFalse();
});

it('resolves localized media metadata through media to only dependent pages', function (): void {
    $dependentPage = Page::factory()->withTranslations()->create();
    $unrelatedPage = Page::factory()->withTranslations()->create();
    $media = Media::factory()->model(Layout::factory()->create())->create();
    $translation = Translation::factory()->translatable($media)->create();
    $translation->unsetRelation('translatable');

    translationOwnershipEdge($dependentPage, $media);

    $plan = resolve(CacheInvalidationRegistry::class)->planForChangedModel($translation);

    expect(planContainsPage($plan->rules, $dependentPage))->toBeTrue()
        ->and(planContainsPage($plan->rules, $unrelatedPage))->toBeFalse()
        ->and(planContainsFlush($plan->rules))->toBeFalse();
});

it('resolves site translations to registered site cache dependencies', function (): void {
    $site = Site::factory()->withTranslations()->create();
    $translation = $site->translations->firstOrFail();
    $translation->unsetRelation('translatable');

    $plan = resolve(CacheInvalidationRegistry::class)->planForChangedModel($translation);

    expect(planContainsFlush($plan->rules))->toBeTrue();
});

it('keeps conservative registered translation behavior for unsupported owners', function (): void {
    $registry = resolve(CacheInvalidationRegistry::class);
    $registry->registerDependency(Translation::class, 'translation-index');
    $layout = Layout::factory()->create();
    $translation = Translation::factory()->translatable($layout)->create();
    $translation->unsetRelation('translatable');

    $plan = $registry->planForChangedModel($translation);

    expect(collect($plan->rules)->contains(
        fn (CacheInvalidationRule $rule): bool => $rule->kind === CacheInvalidationRule::KIND_FORGET_KEY
            && $rule->cacheKey === 'translation-index',
    ))->toBeTrue();
});

it('terminates cyclic ownership graphs and deduplicates page rules', function (): void {
    $page = Page::factory()->withTranslations()->create();
    $layout = Layout::factory()->create();
    $media = Media::factory()->model($layout)->create();
    $translation = Translation::factory()->translatable($media)->create();

    translationOwnershipEdge($page, $layout);
    translationOwnershipEdge($layout, $media);
    translationOwnershipEdge($media, $layout);

    $plan = resolve(CacheInvalidationRegistry::class)->planForChangedModel($translation);
    $matchingRules = collect($plan->rules)->filter(
        fn (CacheInvalidationRule $rule): bool => $rule->kind === CacheInvalidationRule::KIND_PAGE_MODEL
            && $rule->modelType === Page::class
            && $rule->modelId === $page->id,
    );

    expect($matchingRules)->toHaveCount(1)
        ->and(planContainsFlush($plan->rules))->toBeFalse();
});

/**
 * @param  list<CacheInvalidationRule>  $rules
 */
function planContainsPage(array $rules, Page $page): bool
{
    return collect($rules)->contains(
        fn (CacheInvalidationRule $rule): bool => $rule->kind === CacheInvalidationRule::KIND_PAGE_MODEL
            && $rule->modelType === Page::class
            && $rule->modelId === $page->id,
    );
}

/** @param list<CacheInvalidationRule> $rules */
function planContainsFlush(array $rules): bool
{
    return collect($rules)->contains(
        fn (CacheInvalidationRule $rule): bool => $rule->kind === CacheInvalidationRule::KIND_FLUSH_FRONTEND_TAG,
    );
}

function translationOwnershipEdge(Page|Layout|Media $source, Layout|Media $target): ContentGraphEdge
{
    return ContentGraphEdge::query()->create([
        'source_type' => $source::class,
        'source_id' => $source->getKey(),
        'target_type' => $target::class,
        'target_id' => $target->getKey(),
        'kind' => ContentGraphEdgeKind::UsesMedia,
        'strength' => ContentGraphEdgeStrength::Strong,
        'source_package' => 'capell-app/frontend',
        'site_id' => $source instanceof Page ? $source->site_id : null,
    ]);
}
