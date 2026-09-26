<?php

declare(strict_types=1);

use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Language;
use Capell\Core\Models\Media;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Models\Translation;
use Capell\Frontend\Enums\CacheEnum;
use Capell\Frontend\Support\Cache\PageModelCache;
use Capell\Frontend\Tests\Fixtures\Cache\First\Page as FirstExtensionPage;
use Capell\Frontend\Tests\Fixtures\Cache\Second\Page as SecondExtensionPage;
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
    $image = Media::factory()->model($page)->image()->create();
    Translation::factory()->translatable($image)->language($language)->create([
        'meta' => ['alt' => 'Cached page image alternative'],
    ]);

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
    expect($result->image?->relationLoaded('translations'))->toBeTrue();
    expect(data_get($result->image?->translations->first()?->meta, 'alt'))->toBe('Cached page image alternative');
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

it('partitions cached models by their canonical class name', function (): void {
    $language = Language::factory()->createOne();
    $site = Site::factory()->recycle($language)->withTranslations()->create();
    $page = Page::factory()
        ->site($site)
        ->published(CarbonImmutable::now())
        ->withTranslations($language, [], slug: 'extension-page')
        ->createOne();
    $site->load('siteDomains');

    $cache = resolve(PageModelCache::class);
    $firstKey = CacheEnum::pageModel(FirstExtensionPage::class, $page->id, $site->id, $language->id);
    $secondKey = CacheEnum::pageModel(SecondExtensionPage::class, $page->id, $site->id, $language->id);
    $first = $cache->get(FirstExtensionPage::class, $page->id, $site, $language);
    $cache->setToCache($secondKey, $first);
    $second = $cache->get(SecondExtensionPage::class, $page->id, $site, $language);

    expect($firstKey)
        ->not->toBe($secondKey)
        ->and($first)->toBeInstanceOf(FirstExtensionPage::class)
        ->and($second)->toBeInstanceOf(SecondExtensionPage::class);
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

it('rejects foreign site models on cold warm and uncached paths', function (string $mode): void {
    $language = Language::factory()->createOne();
    $ownerSite = Site::factory()->recycle($language)->withTranslations()->create();
    $requestedSite = Site::factory()->recycle($language)->withTranslations()->create();
    $page = Page::factory()->site($ownerSite)->published(CarbonImmutable::now())
        ->withTranslations($language, ['title' => 'Foreign content'])->create();
    $cache = resolve(PageModelCache::class);
    $key = CacheEnum::pageModel(Page::class, $page->id, $requestedSite->id, $language->id);

    if ($mode === 'warm') {
        $cache->setToCache($key, $page);
    }

    expect($cache->get(Page::class, $page->id, $requestedSite, $language, useCache: $mode !== 'uncached'))->toBeNull()
        ->and($cache->getFromCache($key))->not->toBeInstanceOf(Page::class)
        ->and($page->site->id)->toBe($ownerSite->id)
        ->and($cache->get(Page::class, $page->id, $ownerSite, $language)?->id)->toBe($page->id)
        ->and($cache->get(Page::class, $page->id, null, $language)?->id)->toBe($page->id);
})->with(['cold', 'warm', 'uncached']);
