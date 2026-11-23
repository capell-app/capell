<?php

declare(strict_types=1);

use Capell\Core\Enums\CacheTime;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Frontend\Support\Loader\PageCachePolicy;
use Capell\Frontend\Support\Loader\PageLoader;
use Illuminate\Support\Facades\DB;

it('refuses to cache a never-cached page whose type was not loaded with it, without querying for it', function (): void {
    $type = Blueprint::factory()->page()->meta(['cache_time' => CacheTime::Never->value])->createOne();
    $page = Page::query()->whereKey(Page::factory()->type($type)->createOne()->getKey())->sole();
    DB::enableQueryLog();

    expect($page->relationLoaded('blueprint'))->toBeFalse()
        ->and(PageCachePolicy::neverCached($page))->toBeTrue()
        ->and(PageCachePolicy::shouldCache($page))->toBeFalse()
        ->and(DB::getQueryLog())->toBe([])
        ->and($page->relationLoaded('blueprint'))->toBeFalse();
});

it('refuses to cache a page whose type cannot be determined', function (): void {
    $page = Page::factory()->createOne();
    $page->setRelation('blueprint', null);

    expect($page->getAttribute('blueprint_id'))->not->toBeNull()
        ->and(PageCachePolicy::neverCached($page))->toBeTrue()
        ->and(PageCachePolicy::shouldCache($page))->toBeFalse();
});

it('caches a page only when its type was loaded with it and allows caching', function (): void {
    $type = Blueprint::factory()->page()->meta(['cache_time' => CacheTime::Daily->value])->createOne();
    $page = Page::query()->whereKey(Page::factory()->type($type)->createOne()->getKey())->sole();
    DB::enableQueryLog();

    // Not loaded: no answer, so not cached, and no query to find out.
    expect(PageCachePolicy::shouldCache($page))->toBeFalse()
        ->and(PageCachePolicy::neverCached($page))->toBeTrue()
        ->and(DB::getQueryLog())->toBe([]);

    $page->load('blueprint');

    expect(PageCachePolicy::shouldCache($page))->toBeTrue()
        ->and(PageCachePolicy::neverCached($page))->toBeFalse()
        ->and(PageCachePolicy::shouldCache(null))->toBeFalse();
});

it('gets a decided cache policy for every page the page loader resolves', function (CacheTime $cacheTime, bool $cached): void {
    $language = Language::factory()->createOne();
    $site = Site::factory()->recycle($language)->withTranslations()->createOne();
    $type = Blueprint::factory()->page()->meta(['cache_time' => $cacheTime->value])->createOne();
    $created = Page::factory()->site($site)->type($type)->published()
        ->withTranslations($language, ['title' => 'Loaded'], slug: '/loaded')
        ->createOne();

    $page = PageLoader::loadPage(type: $created->getMorphClass(), id: (int) $created->getKey(), site: $site, language: $language);
    assert($page instanceof Page);
    DB::enableQueryLog();

    // The policy never queries, so the loader must hand it the page's type: without it nothing is cached.
    expect($page->relationLoaded('blueprint'))->toBeTrue()
        ->and(PageCachePolicy::shouldCache($page))->toBe($cached)
        ->and(PageCachePolicy::neverCached($page))->toBe(! $cached)
        ->and(DB::getQueryLog())->toBe([]);
})->with([
    'a cacheable page type' => [CacheTime::Daily, true],
    'a never-cached page type' => [CacheTime::Never, false],
]);
