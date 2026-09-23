<?php

declare(strict_types=1);

use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Frontend\Support\Cache\PageHydrator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

it('returns an empty collection when given an empty id array', function (): void {
    $language = Language::factory()->createOne();
    $site = Site::factory()->recycle($language)->withTranslations()->create();

    $result = resolve(PageHydrator::class)->hydrate([], Page::class, $site, $language);

    expect($result)->toHaveCount(0);
});

it('returns pages in the same order as the provided id array', function (): void {
    $language = Language::factory()->createOne();
    $site = Site::factory()->recycle($language)->withTranslations()->create();
    $type = Blueprint::factory()->page()->create();

    $first = Page::factory()->site($site)->type($type)->published(CarbonImmutable::now())
        ->withTranslations($language, [], slug: 'first')->create();
    $second = Page::factory()->site($site)->type($type)->published(CarbonImmutable::now())
        ->withTranslations($language, [], slug: 'second')->create();

    $hydrator = resolve(PageHydrator::class);

    // Request in reverse order
    $result = $hydrator->hydrate([$second->id, $first->id], Page::class, $site, $language);
    $firstResult = expectPresent($result->first());
    $lastResult = expectPresent($result->last());

    expect($firstResult->id)->toBe($second->id);
    expect($lastResult->id)->toBe($first->id);
});

it('skips ids that resolve to null (unpublished or missing)', function (): void {
    $language = Language::factory()->createOne();
    $site = Site::factory()->recycle($language)->withTranslations()->create();
    $type = Blueprint::factory()->page()->create();

    $page = Page::factory()->site($site)->type($type)->published(CarbonImmutable::now())
        ->withTranslations($language, [], slug: 'real')->create();

    $result = resolve(PageHydrator::class)->hydrate([$page->id, 99999], Page::class, $site, $language);
    $firstResult = expectPresent($result->first());

    expect($result)->toHaveCount(1);
    expect($firstResult->id)->toBe($page->id);
});

it('injects parent models from the model cache when withParent is true', function (): void {
    $language = Language::factory()->createOne();
    $site = Site::factory()->recycle($language)->withTranslations()->create();
    $type = Blueprint::factory()->page()->create();

    $parent = Page::factory()->site($site)->type($type)->published(CarbonImmutable::now())
        ->withTranslations($language, ['title' => 'Parent Page'], slug: 'parent')->create();

    $child = Page::factory()->site($site)->type($type)->published(CarbonImmutable::now())
        ->withTranslations($language, [], slug: 'child')
        ->state(['parent_id' => $parent->id])
        ->create();

    $result = resolve(PageHydrator::class)->hydrate(
        ids: [$child->id],
        morphType: Page::class,
        site: $site,
        language: $language,
        withParent: true,
    );
    $firstResult = expectPresent($result->first());
    $parentResult = expectPresent($firstResult->parent);

    expect($parentResult)->not->toBeNull();
    expect($parentResult->id)->toBe($parent->id);
    expect($firstResult->relationLoaded('ancestors'))->toBeTrue();
    expect($firstResult->ancestors->pluck('id')->all())->toBe([$parent->id]);
});

it('excludes foreign ids parents and children from site hydration', function (bool $useCache): void {
    $language = Language::factory()->createOne();
    $site = Site::factory()->recycle($language)->withTranslations()->create();
    $otherSite = Site::factory()->recycle($language)->withTranslations()->create();
    $parent = Page::factory()->site($otherSite)->published(CarbonImmutable::now())->withTranslations($language)->create();
    $page = Page::factory()->site($site)->published(CarbonImmutable::now())->withTranslations($language)->create();
    $child = Page::factory()->site($otherSite)->published(CarbonImmutable::now())->withTranslations($language)->create();
    // Simulate stale imported hierarchy pointers without invoking authoring guards.
    DB::table($page->getTable())->where('id', $page->id)->update(['parent_id' => $parent->id]);
    DB::table($child->getTable())->where('id', $child->id)->update(['parent_id' => $page->id]);

    $result = resolve(PageHydrator::class)->hydrate(
        [$parent->id, $page->id, $child->id],
        Page::class,
        $site,
        $language,
        withParent: true,
        withChildren: true,
        withChildrenCount: true,
        useCache: $useCache,
    );

    expect($result->modelKeys())->toBe([$page->id]);
    $hydrated = expectPresent($result->first());
    expect($hydrated->parent)->toBeNull()
        ->and($hydrated->ancestors)->toBeEmpty()
        ->and($hydrated->children)->toBeEmpty()
        ->and($hydrated->children_count)->toBe(0);
})->with([true, false]);
