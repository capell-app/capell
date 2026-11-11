<?php

declare(strict_types=1);

use Capell\Core\Enums\PageTypeEnum;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Frontend\Data\CacheInvalidationRule;
use Capell\Frontend\Support\Cache\CacheInvalidationRegistry;
use Capell\Frontend\Support\Cache\PageCacheInvalidator;
use Capell\Frontend\Support\Cache\PageModelCache;
use Capell\Frontend\Support\Loader\PageLoader;
use Carbon\CarbonImmutable;

/**
 * @return array{Site, Language, Language}
 */
function fallbackCacheSite(): array
{
    $english = Language::factory()->english()->createOne();
    $french = Language::factory()->french()->createOne(['fallback_language_id' => $english->getKey()]);
    $site = Site::factory()->language($english)->withTranslations([$english, $french])->createOne();
    $site->load('siteDomains');

    return [$site, $english, $french];
}

it('hydrates the fallback translation, in its own language, from the page model cache', function (): void {
    [$site, $english, $french] = fallbackCacheSite();
    $page = Page::factory()->site($site)->published(CarbonImmutable::now())
        ->withTranslations($english, ['title' => 'English only'], slug: 'english-only')
        ->createOne();

    $cached = expectPresent(resolve(PageModelCache::class)->get(Page::class, $page->id, $site, $french));
    $translation = expectPresent($cached->translation);

    expect($translation->title)->toBe('English only')
        ->and($translation->language_id)->toBe($english->getKey())
        ->and($translation->language?->is($english))->toBeTrue();
});

/**
 * A page translated into English only whose French URL survived (a French translation
 * was removed) while its English URL is disabled: the only usable URL is French.
 *
 * @return array{Site, Language, Language, Page}
 */
function fallbackCachePageWithoutServedUrl(): array
{
    [$site, $english, $french] = fallbackCacheSite();
    $page = Page::factory()->site($site)->published(CarbonImmutable::now())
        ->withTranslations([$english, $french], slug: 'p')
        ->createOne();
    $page->translations()->where('language_id', $english->getKey())->update(['title' => 'English only']);
    // A query-level delete, so no model event rewrites the page's URLs.
    $page->translations()->where('language_id', $french->getKey())->forceDelete();
    $page->pageUrls()->where('language_id', $english->getKey())->update(['status' => false]);

    return [$site, $english, $french, $page];
}

it('labels fallback content with its own language and keeps the matched URL when the served language has no usable URL', function (): void {
    [$site, $english, $french, $page] = fallbackCachePageWithoutServedUrl();

    $cached = expectPresent(resolve(PageModelCache::class)->get(Page::class, $page->id, $site, $french));

    expect($cached->translation?->title)->toBe('English only')
        ->and($cached->translation?->language?->is($english))->toBeTrue()
        ->and($cached->pageUrl?->language_id)->toBe($french->getKey());
});

it('labels fallback content with its own language on the wildcard load path', function (): void {
    [$site, $english, $french, $page] = fallbackCachePageWithoutServedUrl();

    $loaded = PageLoader::loadPage(Page::class, $page->id, $site, $french);

    expect($loaded)->toBeInstanceOf(Page::class)
        ->and($loaded?->translation?->title)->toBe('English only')
        ->and($loaded?->translation?->language?->is($english))->toBeTrue()
        ->and($loaded?->pageUrl?->language_id)->toBe($french->getKey());
});

it('keeps the requested language when the page model cache has a real translation', function (): void {
    [$site, $english, $french] = fallbackCacheSite();
    $page = Page::factory()->site($site)->published(CarbonImmutable::now())
        ->withTranslations([$english, $french], slug: 'both')
        ->createOne();
    $page->translations()->where('language_id', $french->getKey())->update(['title' => 'Français']);

    $cached = expectPresent(resolve(PageModelCache::class)->get(Page::class, $page->id, $site, $french));

    expect($cached->translation?->title)->toBe('Français')
        ->and($cached->translation?->language?->is($french))->toBeTrue();
});

it('loads a system page through the fallback language when it has no translation of its own', function (): void {
    [$site, $english, $french] = fallbackCacheSite();
    $blueprint = Blueprint::factory()->page()->createOne(['key' => PageTypeEnum::NotFound->value]);
    Page::factory()->site($site)->type($blueprint)->published(CarbonImmutable::now())
        ->withTranslations($english, ['title' => 'Not found'], slug: 'not-found')
        ->createOne();

    $page = expectPresent(PageLoader::getSystemPage($site, $french, PageTypeEnum::NotFound->value));

    expect($page->translation?->title)->toBe('Not found')
        ->and($page->translation?->language?->is($english))->toBeTrue()
        ->and($page->pageUrl?->language_id)->toBe($english->getKey());
});

it('does not load a system page in a language without translation or fallback', function (): void {
    $english = Language::factory()->english()->createOne();
    $french = Language::factory()->french()->createOne();
    $site = Site::factory()->language($english)->withTranslations([$english, $french])->createOne();
    $blueprint = Blueprint::factory()->page()->createOne(['key' => PageTypeEnum::NotFound->value]);
    Page::factory()->site($site)->type($blueprint)->published(CarbonImmutable::now())
        ->withTranslations($english, ['title' => 'Not found'], slug: 'not-found')
        ->createOne();

    expect(PageLoader::getSystemPage($site, $french, PageTypeEnum::NotFound->value))->toBeNull();
});

it('invalidates the fallback languages that serve a page when its source translation changes', function (): void {
    [$site, $english, $french] = fallbackCacheSite();
    $page = Page::factory()->site($site)->published(CarbonImmutable::now())
        ->withTranslations($english, slug: 'english-only')
        ->createOne();

    $rules = collect(resolve(PageCacheInvalidator::class)->planForPage($page)->rules)
        ->filter(fn (CacheInvalidationRule $rule): bool => $rule->kind === CacheInvalidationRule::KIND_PAGE_MODEL)
        ->map(fn (CacheInvalidationRule $rule): ?int => $rule->languageId)
        ->values()
        ->all();

    expect($rules)->toContain($english->getKey(), $french->getKey());
});

it('invalidates cached system and home pages when a fallback chain changes', function (): void {
    [, , $french] = fallbackCacheSite();
    $french->fallback_language_id = null;

    $patterns = collect(resolve(CacheInvalidationRegistry::class)->planForChangedModel($french)->rules)
        ->filter(fn (CacheInvalidationRule $rule): bool => $rule->kind === CacheInvalidationRule::KIND_INVALIDATE_PATTERN)
        ->map(fn (CacheInvalidationRule $rule): ?string => $rule->cacheKey)
        ->all();

    expect($patterns)->toContain('page-*', 'system-page-*', 'homepage-*');
});
