<?php

declare(strict_types=1);

use Capell\Core\Enums\PageOrderEnum;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Frontend\Data\PageListingSpec;
use Capell\Frontend\Enums\CacheEnum;
use Capell\Frontend\Support\Cache\PageListingCache;

it('produces the same cache key regardless of pagination page', function (): void {
    $specA = new PageListingSpec(
        languageId: 1,
        siteId: 2,
        type: null,
        ordering: PageOrderEnum::Latest,
        pageType: null,
        pageGroup: null,
        typeKey: null,
        morphModel: null,
        pageableId: null,
        pageableType: null,
        optionalLanguage: false,
        onlyListableTypes: true,
        limit: 10,
        cacheKeySuffix: '',
    );

    $specB = new PageListingSpec(
        languageId: 1,
        siteId: 2,
        type: null,
        ordering: PageOrderEnum::Latest,
        pageType: null,
        pageGroup: null,
        typeKey: null,
        morphModel: null,
        pageableId: null,
        pageableType: null,
        optionalLanguage: false,
        onlyListableTypes: true,
        limit: 10,
        cacheKeySuffix: '',
    );

    expect($specA->toCacheKey())->toBe($specB->toCacheKey());
});

it('produces different keys for different filter parameters', function (): void {
    $base = new PageListingSpec(1, 2, null, null, null, null, null, null, null, null, false, true, null, '');
    $withType = new PageListingSpec(1, 2, 'children', null, null, null, null, null, null, null, false, true, null, '');

    expect($base->toCacheKey())->not->toBe($withType->toCacheKey());
});

it('generates a pageIds key that includes the generation', function (): void {
    $key = CacheEnum::pageIds('page-ids-1-2-limit-10', generation: 3);

    expect($key)->toBe('page-ids-1-2-limit-10-gen-3');
});

it('generates a pageModel key per type/id/site/lang', function (): void {
    $key = CacheEnum::pageModel('App\\Models\\Article', 42, siteId: 1, languageId: 2);

    expect($key)->toStartWith('page-model-');
    expect($key)->toContain('42');
});

it('generates a listingGeneration key per site and language', function (): void {
    $key = CacheEnum::listingGeneration(siteId: 1, languageId: 2);

    expect($key)->toBe('listing-gen-1-2');
});

it('stores and returns an ordered int array from the listing cache', function (): void {
    $language = Language::factory()->createOne();
    $site = Site::factory()->recycle($language)->withTranslations()->create();
    $type = Blueprint::factory()->page()->create();

    Page::factory()->site($site)->blueprint($type)->withTranslations($language, [], slug: 'a')->create();
    Page::factory()->site($site)->blueprint($type)->withTranslations($language, [], slug: 'b')->create();

    $spec = new PageListingSpec(
        languageId: $language->id,
        siteId: $site->id,
        type: null,
        ordering: null,
        pageType: null,
        pageGroup: null,
        typeKey: null,
        morphModel: null,
        pageableId: null,
        pageableType: null,
        optionalLanguage: false,
        onlyListableTypes: true,
        limit: null,
        cacheKeySuffix: '',
    );

    $cache = resolve(PageListingCache::class);

    $ids = $cache->getIds($spec, fn (): array => Page::query()->pluck('id')->all());

    expect($ids)->toBeArray()->not->toBeEmpty();
});

it('returns cached ids on second call without executing the loader', function (): void {
    $language = Language::factory()->createOne();
    $site = Site::factory()->recycle($language)->withTranslations()->create();

    $spec = new PageListingSpec($language->id, $site->id, null, null, null, null, null, null, null, null, false, true, null, '');
    $cache = resolve(PageListingCache::class);

    $callCount = 0;
    $loader = function () use (&$callCount): array {
        $callCount++;

        return [1, 2, 3];
    };

    $cache->getIds($spec, $loader);
    $cache->getIds($spec, $loader);

    expect($callCount)->toBe(1);
});

it('invalidates listing entries by incrementing the generation counter', function (): void {
    $language = Language::factory()->createOne();
    $site = Site::factory()->recycle($language)->withTranslations()->create();

    $spec = new PageListingSpec($language->id, $site->id, null, null, null, null, null, null, null, null, false, true, null, '');
    $cache = resolve(PageListingCache::class);

    $callCount = 0;
    $loader = function () use (&$callCount): array {
        $callCount++;

        return [10, 20];
    };

    $cache->getIds($spec, $loader);
    $cache->invalidateListings($site->id, $language->id);
    $cache->getIds($spec, $loader);

    expect($callCount)->toBe(2);
});
