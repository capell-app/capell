<?php

declare(strict_types=1);

use Capell\Core\Enums\PageTypeEnum;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Frontend\Data\CacheInvalidationRule;
use Capell\Frontend\Enums\CacheEnum;
use Capell\Frontend\Support\Cache\CacheInvalidationRegistry;
use Capell\Frontend\Support\Cache\PageCacheInvalidator;
use Capell\Frontend\Support\Cache\PageModelCache;
use Capell\Frontend\Support\Loader\PageLoader;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

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

it('refreshes a cached fallback system page when a page in the requested language is created', function (): void {
    [$site, $english, $french] = fallbackCacheSite();
    $blueprint = Blueprint::factory()->page()->createOne(['key' => PageTypeEnum::NotFound->value]);
    Page::factory()->site($site)->type($blueprint)->published(CarbonImmutable::now()->subDay())
        ->withTranslations($english, ['title' => 'Not found'], slug: 'not-found')
        ->createOne();

    expect(PageLoader::getSystemPage($site, $french, PageTypeEnum::NotFound->value)?->translation?->title)->toBe('Not found');

    Page::factory()->site($site)->type($blueprint)->published(CarbonImmutable::now()->subDay())
        ->withTranslations($french, ['title' => 'Page introuvable'], slug: 'introuvable')
        ->createOne();

    $page = expectPresent(PageLoader::getSystemPage($site, $french, PageTypeEnum::NotFound->value));

    expect($page->translation?->title)->toBe('Page introuvable')
        ->and($page->translation?->language_id)->toBe($french->getKey());
});

it('refreshes a cached fallback system page when the served page is edited', function (): void {
    [$site, $english, $french] = fallbackCacheSite();
    $blueprint = Blueprint::factory()->page()->createOne(['key' => PageTypeEnum::NotFound->value]);
    $source = Page::factory()->site($site)->type($blueprint)->published(CarbonImmutable::now()->subDay())
        ->withTranslations($english, ['title' => 'Not found'], slug: 'not-found')
        ->createOne();

    expect(PageLoader::getSystemPage($site, $french, PageTypeEnum::NotFound->value))->not->toBeNull();

    // Scheduling the page into the future takes it off the site, fallback included.
    $source->update(['visible_from' => CarbonImmutable::now()->addWeek(), 'visible_until' => null]);

    expect(PageLoader::getSystemPage($site, $french, PageTypeEnum::NotFound->value))->toBeNull();
});

it('refreshes a cached fallback system page when the language fallback chain changes', function (): void {
    [$site, $english, $french] = fallbackCacheSite();
    $blueprint = Blueprint::factory()->page()->createOne(['key' => PageTypeEnum::NotFound->value]);
    Page::factory()->site($site)->type($blueprint)->published(CarbonImmutable::now()->subDay())
        ->withTranslations($english, ['title' => 'Not found'], slug: 'not-found')
        ->createOne();

    expect(PageLoader::getSystemPage($site, $french, PageTypeEnum::NotFound->value))->not->toBeNull();

    $french->update(['fallback_language_id' => null]);

    expect(PageLoader::getSystemPage($site, $french->refresh(), PageTypeEnum::NotFound->value))->toBeNull();
});

it('forgets the cached system page in every language when the system page is deleted', function (): void {
    [$site, $english, $french] = fallbackCacheSite();
    $blueprint = Blueprint::factory()->page()->createOne(['key' => PageTypeEnum::NotFound->value]);
    $page = Page::factory()->site($site)->type($blueprint)->published(CarbonImmutable::now()->subDay())
        ->withTranslations($english, ['title' => 'Not found'], slug: 'not-found')
        ->createOne();

    expect(PageLoader::getSystemPage($site, $english, PageTypeEnum::NotFound->value))->not->toBeNull()
        ->and(PageLoader::getSystemPage($site, $french, PageTypeEnum::NotFound->value))->not->toBeNull();

    $page->delete();

    expect(PageLoader::getSystemPage($site, $english, PageTypeEnum::NotFound->value))->toBeNull()
        ->and(PageLoader::getSystemPage($site, $french, PageTypeEnum::NotFound->value))->toBeNull();
});

it('stops serving a deleted translation from the system page and page model caches', function (): void {
    $english = Language::factory()->english()->createOne();
    $french = Language::factory()->french()->createOne();
    $site = Site::factory()->language($english)->withTranslations([$english, $french])->createOne();
    $site->load('siteDomains');

    $blueprint = Blueprint::factory()->page()->createOne(['key' => PageTypeEnum::NotFound->value]);
    $page = Page::factory()->site($site)->type($blueprint)->published(CarbonImmutable::now()->subDay())
        ->withTranslations([$english, $french], slug: 'not-found')
        ->createOne();
    $page->translations()->where('language_id', $french->getKey())->update(['title' => 'Page introuvable']);
    $models = resolve(PageModelCache::class);

    expect(PageLoader::getSystemPage($site, $french, PageTypeEnum::NotFound->value)?->translation?->title)->toBe('Page introuvable')
        ->and($models->get(Page::class, $page->id, $site, $french)?->translation?->title)->toBe('Page introuvable');

    $page->translations()->where('language_id', $french->getKey())->sole()->delete();

    expect(PageLoader::getSystemPage($site, $french, PageTypeEnum::NotFound->value))->toBeNull()
        ->and($models->get(Page::class, $page->id, $site, $french)?->translation?->title)->not->toBe('Page introuvable');
});

it('refreshes the cached home page when the home page is created, edited or deleted', function (): void {
    [$site, $english, $french] = fallbackCacheSite();
    $home = Blueprint::factory()->page()->createOne(['key' => 'home']);

    expect(PageLoader::getSiteHomePage($site, $english))->toBeNull();

    $page = Page::factory()->site($site)->type($home)->published(CarbonImmutable::now()->subDay())
        ->withTranslations([$english, $french], slug: 'home')
        ->createOne();

    expect(PageLoader::getSiteHomePage($site, $english)?->is($page))->toBeTrue()
        ->and(PageLoader::getSiteHomePage($site, $french)?->is($page))->toBeTrue();

    $page->update(['visible_from' => CarbonImmutable::now()->addWeek(), 'visible_until' => null]);

    expect(PageLoader::getSiteHomePage($site, $english))->toBeNull()
        ->and(PageLoader::getSiteHomePage($site, $french))->toBeNull();

    $page->update(['visible_from' => CarbonImmutable::now()->subDay()]);

    expect(PageLoader::getSiteHomePage($site, $french)?->is($page))->toBeTrue();

    $page->delete();

    expect(PageLoader::getSiteHomePage($site, $english))->toBeNull()
        ->and(PageLoader::getSiteHomePage($site, $french))->toBeNull();
});

it('forgets cached system and home pages when every page cache is invalidated', function (): void {
    [$site, $english] = fallbackCacheSite();
    $blueprint = Blueprint::factory()->page()->createOne(['key' => PageTypeEnum::NotFound->value]);
    $page = Page::factory()->site($site)->type($blueprint)->published(CarbonImmutable::now()->subDay())
        ->withTranslations($english, ['title' => 'Not found'], slug: 'not-found')
        ->createOne();

    expect(PageLoader::getSystemPage($site, $english, PageTypeEnum::NotFound->value))->not->toBeNull();

    // A query-level change fires no model event, so only the class-wide plan can retire it.
    Page::query()->whereKey($page->getKey())->update(['visible_from' => CarbonImmutable::now()->addWeek(), 'visible_until' => null]);
    resolve(CacheInvalidationRegistry::class)->invalidateForModel(Page::class);

    expect(PageLoader::getSystemPage($site, $english, PageTypeEnum::NotFound->value))->toBeNull();
});

it('plans a page change from its loaded blueprint without querying blueprints', function (): void {
    [$site, $english] = fallbackCacheSite();
    $blueprint = Blueprint::factory()->page()->createOne(['key' => PageTypeEnum::NotFound->value]);
    $created = Page::factory()->site($site)->type($blueprint)->published(CarbonImmutable::now()->subDay())
        ->withTranslations($english, ['title' => 'Not found'], slug: 'not-found')
        ->createOne();
    $page = Page::query()->with(['blueprint', 'translations'])->whereKey($created->getKey())->firstOrFail();
    resolve(PageCacheInvalidator::class)->planForPage($page);

    DB::enableQueryLog();
    DB::flushQueryLog();

    try {
        $rules = resolve(PageCacheInvalidator::class)->planForPage($page)->rules;
        $queries = collect(DB::getQueryLog())->pluck('query')->filter(static fn (string $query): bool => str_contains($query, 'blueprints'))->all();
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
    }

    $keys = collect($rules)->map(fn (CacheInvalidationRule $rule): ?string => $rule->cacheKey)->filter()->all();

    expect($queries)->toBe([])
        ->and($keys)->toContain(CacheEnum::systemPage(PageTypeEnum::NotFound->value, $site->id, $english->getKey()));
});

it('forgets the old system page when a page leaves its blueprint type in a transaction that saves it again', function (): void {
    [$site, $english] = fallbackCacheSite();
    $notFound = Blueprint::factory()->page()->createOne(['key' => PageTypeEnum::NotFound->value]);
    $standard = Blueprint::factory()->page()->createOne(['key' => 'standard-page']);
    $page = Page::factory()->site($site)->type($notFound)->published(CarbonImmutable::now()->subDay())
        ->withTranslations($english, ['title' => 'Not found'], slug: 'not-found')
        ->createOne();

    expect(PageLoader::getSystemPage($site, $english, PageTypeEnum::NotFound->value))->not->toBeNull();

    // After commit the model only remembers its last save, which did not touch the blueprint.
    DB::transaction(function () use ($page, $standard): void {
        $page->update(['blueprint_id' => $standard->getKey()]);
        $page->update(['name' => 'Renamed']);
    });

    expect(PageLoader::getSystemPage($site, $english, PageTypeEnum::NotFound->value))->toBeNull();
});

it('forgets the old language entries when a translation moves language in a transaction that saves it again', function (): void {
    $english = Language::factory()->english()->createOne();
    $french = Language::factory()->french()->createOne();
    $german = Language::factory()->german()->createOne();
    $site = Site::factory()->language($english)->withTranslations([$english, $french, $german])->createOne();
    $site->load('siteDomains');

    $blueprint = Blueprint::factory()->page()->createOne(['key' => PageTypeEnum::NotFound->value]);
    $page = Page::factory()->site($site)->type($blueprint)->published(CarbonImmutable::now()->subDay())
        ->withTranslations([$english, $french], slug: 'not-found')
        ->createOne();
    $translation = $page->translations()->where('language_id', $french->getKey())->sole();
    $translation->update(['title' => 'Page introuvable']);

    expect(PageLoader::getSystemPage($site, $french, PageTypeEnum::NotFound->value)?->translation?->title)->toBe('Page introuvable');

    DB::transaction(function () use ($translation, $german): void {
        $translation->update(['language_id' => $german->getKey()]);
        $translation->update(['title' => 'Seite nicht gefunden']);
    });

    expect(PageLoader::getSystemPage($site, $french, PageTypeEnum::NotFound->value))->toBeNull();
});
