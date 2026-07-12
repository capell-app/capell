<?php

declare(strict_types=1);

use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Site;
use Capell\Frontend\Actions\GetPageVariablesAction;
use Capell\Frontend\Support\Loader\PageLoader;
use Carbon\CarbonImmutable;

it('loads canonical child pages with localized url and translation relations', function (): void {
    $language = Language::factory()->createOne();
    $site = Site::factory()->recycle($language)->withTranslations()->create();
    $type = Blueprint::factory()->page()->create();
    $canonicalPage = Page::factory()
        ->site($site)
        ->blueprint($type)
        ->published()
        ->withTranslations($language, ['title' => 'Canonical'], slug: '/canonical')
        ->create();
    $alternatePage = Page::factory()
        ->site($site)
        ->blueprint($type)
        ->canonicalPage($canonicalPage)
        ->published()
        ->withTranslations($language, ['title' => 'Alternate'], slug: '/alternate')
        ->create();

    $pages = PageLoader::getCanonicalPages($canonicalPage, $language);
    $loadedPage = expectPresent($pages->firstWhere('id', $alternatePage->id));
    assert($loadedPage->pageUrl !== null);
    assert($loadedPage->pageUrl->language !== null);
    assert($loadedPage->translation !== null);
    assert($loadedPage->translation->language !== null);

    expect($pages->pluck('id')->all())->toContain($alternatePage->id)
        ->and($loadedPage->pageUrl->language->is($language))->toBeTrue()
        ->and($loadedPage->translation->language->is($language))->toBeTrue();
});

// ─────────────────────────────────────────────────────────────
// getErrorPage: returns null when no error page exists
// ─────────────────────────────────────────────────────────────

it('getErrorPage returns null when no error-type page exists for the site', function (): void {
    $site = Site::factory()->createOne();
    $language = Language::factory()->createOne();

    $result = PageLoader::getErrorPage(site: $site, language: $language);

    expect($result)->toBeNull();
});

// ─────────────────────────────────────────────────────────────
// getErrorPage: withEvents = false path
// ─────────────────────────────────────────────────────────────

it('getErrorPage with withEvents false returns null when no error page exists', function (): void {
    $site = Site::factory()->createOne();
    $language = Language::factory()->createOne();

    $result = PageLoader::getErrorPage(site: $site, language: $language, withEvents: false);

    expect($result)->toBeNull();
});

it('getErrorPage with withEvents false returns the page when one exists', function (): void {
    $language = Language::factory()->createOne();
    $site = Site::factory()->recycle($language)->withTranslations()->create();
    $errorType = Blueprint::query()->where('key', 'error')->first()
        ?? Blueprint::factory()->page()->state(['key' => 'error'])->create();
    $errorPage = Page::factory()
        ->site($site)
        ->blueprint($errorType)
        ->withTranslations($language)
        ->create();

    $result = expectPresent(PageLoader::getErrorPage(site: $site, language: $language, withEvents: false));

    expect($result)->toBeInstanceOf(Page::class)
        ->and($result->getKey())->toBe($errorPage->getKey());
});

// ─────────────────────────────────────────────────────────────
// getUrlById: returns null when no matching PageUrl exists
// ─────────────────────────────────────────────────────────────

it('getUrlById returns null when no url exists for the given page', function (): void {
    $site = Site::factory()->createOne();
    $language = Language::factory()->createOne();

    $result = PageLoader::getUrlById(
        pageType: 'page',
        pageId: 99999,
        site: $site,
        language: $language,
    );

    expect($result)->toBeNull();
});

it('getUrlById returns null for unavailable morph aliases', function (): void {
    $site = Site::factory()->createOne();
    $language = Language::factory()->createOne();

    PageUrl::withoutEvents(fn (): PageUrl => PageUrl::factory()
        ->site($site)
        ->language($language)
        ->state([
            'pageable_type' => 'article',
            'pageable_id' => 99999,
            'url' => '/stale-by-id',
        ])
        ->create());

    $result = PageLoader::getUrlById(
        pageType: 'article',
        pageId: 99999,
        site: $site,
        language: $language,
    );

    expect($result)->toBeNull();
});

it('getUrlById resolves published page urls without model events', function (): void {
    $language = Language::factory()->createOne();
    $site = Site::factory()->recycle($language)->withTranslations()->create();
    $page = Page::factory()
        ->site($site)
        ->published()
        ->withTranslations($language, ['title' => 'Lookup'], slug: '/lookup-by-id')
        ->create();

    $result = expectPresent(PageLoader::getUrlById(
        pageType: $page->getMorphClass(),
        pageId: $page->getKey(),
        site: $site,
        language: $language,
        withEvents: false,
    ));

    expect($result)->toBeInstanceOf(PageUrl::class)
        ->and($result->pageable_id)->toBe($page->getKey())
        ->and($result->site->is($site))->toBeTrue()
        ->and($result->language->is($language))->toBeTrue();
});

// ─────────────────────────────────────────────────────────────
// loadPage: returns null when page is not found
// ─────────────────────────────────────────────────────────────

it('loadPage returns null when the pageable does not exist', function (): void {
    $site = Site::factory()->createOne();
    $language = Language::factory()->createOne();

    $result = PageLoader::loadPage(
        type: 'page',
        id: 99999,
        site: $site,
        language: $language,
    );

    expect($result)->toBeNull();
});

it('loadPage hydrates parent translation for public page variables', function (): void {
    $language = Language::factory()->createOne();
    $site = Site::factory()->recycle($language)->withTranslations()->create();
    $type = Blueprint::factory()->page()->create();
    $parent = Page::factory()
        ->site($site)
        ->blueprint($type)
        ->published()
        ->withTranslations($language, ['title' => 'Article'], slug: '/articles')
        ->create();
    $child = Page::factory()
        ->site($site)
        ->blueprint($type)
        ->parent($parent)
        ->published()
        ->withTranslations($language, ['title' => 'Child'], slug: '/articles/child')
        ->create();

    $result = PageLoader::loadPage(
        type: $child->getMorphClass(),
        id: $child->getKey(),
        site: $site,
        language: $language,
    );

    $result = expectPresent($result);

    $parent = expectPresent($result->parent);

    expect($result)->toBeInstanceOf(Page::class)
        ->and($result->relationLoaded('parent'))->toBeTrue()
        ->and($parent->relationLoaded('translation'))->toBeTrue()
        ->and(GetPageVariablesAction::run($result, $site)['parent'])->toBe('Articles')
        ->and(GetPageVariablesAction::run($result, $site)['page']['translation']['title'])->toBe('Child');
});

it('loadPage hydrates canonical page urls across available site languages', function (): void {
    $english = Language::factory()->english()->create();
    $french = Language::factory()->french()->create();
    $site = Site::factory()->recycle($english)->withTranslations([$english, $french])->create();
    $canonicalPage = Page::factory()
        ->site($site)
        ->published()
        ->withTranslations([$english, $french], [
            $english->id => ['title' => 'Canonical EN'],
            $french->id => ['title' => 'Canonical FR'],
        ], '/canonical')
        ->create();
    $page = Page::factory()
        ->site($site)
        ->canonicalPage($canonicalPage)
        ->published()
        ->withTranslations($english, ['title' => 'Loaded'], '/loaded')
        ->create();

    $result = expectPresent(PageLoader::loadPage(
        type: $page->getMorphClass(),
        id: $page->getKey(),
        site: $site->fresh(['siteDomains']),
        language: $english,
    ));
    $canonicalPage = expectPresent($result->canonicalPage);
    $canonicalUrl = expectPresent($canonicalPage->pageUrls->firstWhere('language_id', $french->id));

    expect($canonicalPage->relationLoaded('pageUrls'))->toBeTrue()
        ->and($canonicalUrl->language->is($french))->toBeTrue()
        ->and($canonicalUrl->siteDomain->language_id)->toBe($french->id);
});

// ─────────────────────────────────────────────────────────────
// getSiteHomePage: returns null when no home page exists
// ─────────────────────────────────────────────────────────────

it('getSiteHomePage returns null when no home page is published', function (): void {
    $site = Site::factory()->createOne();
    $language = Language::factory()->createOne();

    $result = PageLoader::getSiteHomePage(site: $site, language: $language);

    expect($result)->toBeNull();
});

// ─────────────────────────────────────────────────────────────
// getPageAncestors: returns null when page has no hierarchy
// ─────────────────────────────────────────────────────────────

it('getPageAncestors returns null when page does not support hierarchy', function (): void {
    // Page does not support hierarchy when it's not a nested-set pageable.
    // We can verify by calling with a freshly created page on a site with no ancestors.
    $language = Language::factory()->createOne();
    $site = Site::factory()->recycle($language)->withTranslations()->create();
    $page = Page::factory()->site($site)->withTranslations($language)->create();

    // Page::hasPageHierarchy() returns true for standard pages, so we verify
    // that an empty ancestor collection returns null (isEmpty → null branch).
    $result = PageLoader::getPageAncestors(page: $page, language: $language, site: $site);

    // A root-level page with no ancestors → isEmpty() is true → null is returned.
    expect($result)->toBeNull();
});

it('getPageAncestors returns localized published ancestors in hierarchy order', function (): void {
    $language = Language::factory()->createOne();
    $site = Site::factory()->recycle($language)->withTranslations()->create();
    $type = Blueprint::factory()->page()->create();
    $grandparent = Page::factory()
        ->site($site)
        ->blueprint($type)
        ->published()
        ->withTranslations($language, ['title' => 'Grandparent'], slug: '/grandparent')
        ->create();
    $parent = Page::factory()
        ->site($site)
        ->parent($grandparent)
        ->blueprint($type)
        ->published()
        ->withTranslations($language, ['title' => 'Parent'], slug: '/grandparent/parent')
        ->create();
    $child = Page::factory()
        ->site($site)
        ->parent($parent)
        ->blueprint($type)
        ->published()
        ->withTranslations($language, ['title' => 'Child'], slug: '/grandparent/parent/child')
        ->create();

    $ancestors = expectPresent(PageLoader::getPageAncestors(page: $child, language: $language, site: $site));
    $firstAncestor = $ancestors->first();
    $lastAncestor = $ancestors->last();

    assert($firstAncestor instanceof Page);
    assert($firstAncestor->translation !== null);
    assert($firstAncestor->translation->language !== null);
    assert($lastAncestor instanceof Page);
    assert($lastAncestor->pageUrl !== null);
    assert($lastAncestor->pageUrl->language !== null);

    expect($ancestors->pluck('id')->all())->toBe([$grandparent->id, $parent->id])
        ->and($firstAncestor->translation->language->is($language))->toBeTrue()
        ->and($lastAncestor->pageUrl->language->is($language))->toBeTrue();
});

it('returns the previous public sibling for next and previous navigation', function (): void {
    $language = Language::factory()->createOne();
    $site = Site::factory()->recycle($language)->withTranslations()->create();
    $type = Blueprint::factory()->page()->meta(['with_next_prev' => true])->create();
    $parent = Page::factory()
        ->site($site)
        ->blueprint($type)
        ->published(CarbonImmutable::parse('2026-04-01 09:00:00'))
        ->state(['order' => 1])
        ->withTranslations($language, ['title' => 'Parent'], slug: '/parent')
        ->create();
    $previous = Page::factory()
        ->site($site)
        ->parent($parent)
        ->blueprint($type)
        ->published(CarbonImmutable::parse('2026-04-01 10:00:00'))
        ->state(['order' => 2])
        ->withTranslations($language, ['title' => 'Previous'], slug: '/previous')
        ->create();
    $current = Page::factory()
        ->site($site)
        ->parent($parent)
        ->blueprint($type)
        ->published(CarbonImmutable::parse('2026-04-01 11:00:00'))
        ->state(['order' => 3])
        ->withTranslations($language, ['title' => 'Current'], slug: '/current')
        ->create();

    $result = expectPresent(PageLoader::getPreviousPage($current, $site, $language));

    expect($result)->toBeInstanceOf(Page::class)
        ->and($result->id)->toBe($previous->id)
        ->and($result->pageUrl->language->is($language))->toBeTrue();
});
