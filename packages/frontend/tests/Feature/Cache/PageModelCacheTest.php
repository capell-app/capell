<?php

declare(strict_types=1);

use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Frontend\Support\Cache\PageModelCache;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

it('returns null for a non-existent page', function (): void {
    $language = Language::factory()->createOne();
    $site = Site::factory()->recycle($language)->withTranslations()->create();
    $site->load('siteDomains');

    $cache = resolve(PageModelCache::class);

    $result = $cache->get(Page::class, id: 99999, site: $site, language: $language);

    expect($result)->toBeNull();
});

it('returns a hydrated page with translation and pageUrl on the first call', function (): void {
    $language = Language::factory()->createOne();
    $site = Site::factory()->recycle($language)->withTranslations()->create();
    $type = Blueprint::factory()->page()->create();

    $page = Page::factory()
        ->site($site)
        ->type($type)
        ->published(CarbonImmutable::now())
        ->withTranslations($language, ['title' => 'Cached Page'], slug: 'cached-page')
        ->create();

    $siteDomainQueries = 0;

    DB::listen(function (QueryExecuted $query) use (&$siteDomainQueries): void {
        if (str_contains($query->sql, 'site_domains')) {
            $siteDomainQueries++;
        }
    });

    $cache = resolve(PageModelCache::class);
    $result = expectPresent($cache->get(Page::class, $page->id, $site, $language));
    $translation = expectPresent($result->translation);
    $pageUrl = expectPresent($result->pageUrl);

    expect($result)->not->toBeNull();
    expect($translation->title)->toBe('Cached Page');
    expect($pageUrl)->not->toBeNull();
    expect($pageUrl->relationLoaded('siteDomain'))->toBeTrue();
    expect($siteDomainQueries)->toBe(0);
});

it('does not hit the database on a warm cache call', function (): void {
    $language = Language::factory()->createOne();
    $site = Site::factory()->recycle($language)->withTranslations()->create();
    $type = Blueprint::factory()->page()->create();

    $page = Page::factory()
        ->site($site)
        ->type($type)
        ->published(CarbonImmutable::now())
        ->withTranslations($language, [], slug: 'warm-test')
        ->create();

    $cache = resolve(PageModelCache::class);

    // Warm the cache
    $cache->get(Page::class, $page->id, $site, $language);

    DB::flushQueryLog();
    DB::enableQueryLog();

    // Second call — should be fully from cache
    $cache->get(Page::class, $page->id, $site, $language);

    expect(DB::getQueryLog())->toBeEmpty();
});

it('invalidates a specific model entry by key', function (): void {
    $language = Language::factory()->createOne();
    $site = Site::factory()->recycle($language)->withTranslations()->create();
    $type = Blueprint::factory()->page()->create();

    $page = Page::factory()
        ->site($site)
        ->type($type)
        ->published(CarbonImmutable::now())
        ->withTranslations($language, ['title' => 'Before'], slug: 'before-page')
        ->create();

    $cache = resolve(PageModelCache::class);
    $cache->get(Page::class, $page->id, $site, $language);

    // Simulate a title change in DB
    $page->translation->update(['title' => 'After']);

    $cache->invalidate(Page::class, $page->id, $site->id, $language->id);

    $result = expectPresent($cache->get(Page::class, $page->id, $site, $language));
    $translation = expectPresent($result->translation);
    expect($translation->title)->toBe('After');
});
