<?php

declare(strict_types=1);

use Capell\Core\Enums\UrlTypeEnum;
use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Site;
use Capell\Frontend\Actions\ResolvePageCanonicalUrlAction;
use Capell\Frontend\Actions\ResolvePageRobotsDirectivesAction;
use Illuminate\Database\Eloquent\Model;

it('resolves page-level robots directives before translation directives', function (): void {
    $language = Language::factory()->createOne();
    $site = Site::factory()->language($language)->withTranslations($language)->create();
    $page = Page::factory()
        ->site($site)
        ->meta('robots', ['noindex' => true, 'nofollow'])
        ->withTranslations($language, [
            'meta' => [
                'robots' => ['noarchive', 'nofollow'],
            ],
        ])
        ->create();

    expect(ResolvePageRobotsDirectivesAction::run($page->fresh(['translations']), $language))
        ->toBe(['noindex', 'nofollow', 'noarchive']);
});

it('resolves scalar robots directives without lazy loading translations', function (): void {
    $language = Language::factory()->createOne();
    $site = Site::factory()->language($language)->withTranslations($language)->create();
    $page = Page::factory()
        ->site($site)
        ->withTranslations($language, [
            'meta' => [
                'robots' => 'noindex',
            ],
        ])
        ->create()
        ->fresh(['translation']);

    Model::preventLazyLoading();

    try {
        expect(ResolvePageRobotsDirectivesAction::run($page, $language))->toBe(['noindex']);
    } finally {
        Model::preventLazyLoading(false);
    }
});

it('splits comma separated scalar robots directives', function (): void {
    $language = Language::factory()->createOne();
    $site = Site::factory()->language($language)->withTranslations($language)->create();
    $page = Page::factory()
        ->site($site)
        ->withTranslations($language, [
            'meta' => [
                'robots' => 'noindex, nofollow',
            ],
        ])
        ->create()
        ->fresh(['translation']);

    expect(ResolvePageRobotsDirectivesAction::run($page, $language))->toBe(['noindex', 'nofollow']);
});

it('resolves explicit canonical urls before canonical pages', function (): void {
    $language = Language::factory()->createOne();
    $site = Site::factory()->language($language)->withTranslations($language)->create();
    $canonicalPage = Page::factory()->site($site)->withTranslations($language, slug: '/canonical')->create();
    $page = Page::factory()
        ->site($site)
        ->canonicalPage($canonicalPage)
        ->meta('canonical_url', 'https://example.test/manual')
        ->withTranslations($language, slug: '/alias')
        ->create();

    expect(ResolvePageCanonicalUrlAction::run($page->fresh(['canonicalPage.pageUrls.siteDomain', 'pageUrl', 'pageUrls']), $language))
        ->toBe('https://example.test/manual');
});

it('resolves explicit canonical urls without lazy loading page type metadata', function (): void {
    $language = Language::factory()->createOne();
    $page = Page::factory()
        ->meta('canonical_url', 'https://example.test/manual')
        ->make();

    Model::preventLazyLoading();

    try {
        expect(ResolvePageCanonicalUrlAction::run($page, $language))
            ->toBe('https://example.test/manual');
    } finally {
        Model::preventLazyLoading(false);
    }
});

it('falls back to the related canonical page url', function (): void {
    $language = Language::factory()->createOne();
    $site = Site::factory()->language($language)->withTranslations($language)->create();
    $canonicalPage = Page::factory()->site($site)->withTranslations($language, slug: '/canonical')->create();
    $page = Page::factory()
        ->site($site)
        ->canonicalPage($canonicalPage)
        ->withTranslations($language, slug: '/duplicate')
        ->create();

    expect(ResolvePageCanonicalUrlAction::run($page->fresh(['canonicalPage.pageUrls.siteDomain', 'pageUrl', 'pageUrls']), $language))
        ->toContain('/canonical');
});

it('resolves canonical page urls in the requested language', function (): void {
    $english = Language::factory()->createOne(['code' => 'en']);
    $french = Language::factory()->createOne(['code' => 'fr']);
    $site = Site::factory()->language($english)->withTranslations(collect([$english, $french]))->create();
    $canonicalPage = Page::factory()->site($site)->withTranslations($english, slug: '/canonical')->create();
    PageUrl::factory()->page($canonicalPage)->site($site)->language($french)->state(['url' => '/fr/canonique'])->create();
    $page = Page::factory()
        ->site($site)
        ->canonicalPage($canonicalPage)
        ->withTranslations($english, slug: '/duplicate')
        ->create();

    expect(ResolvePageCanonicalUrlAction::run($page->fresh(['canonicalPage.pageUrls.siteDomain', 'pageUrl', 'pageUrls']), $french))
        ->toContain('/fr/canonique');
});

it('resolves alias page urls to the requested language canonical page url', function (): void {
    $language = Language::factory()->createOne();
    $site = Site::factory()->language($language)->withTranslations($language)->create();
    $page = Page::factory()->site($site)->withTranslations($language, slug: '/canonical')->create();
    $alias = PageUrl::factory()
        ->page($page)
        ->site($site)
        ->language($language)
        ->type(UrlTypeEnum::Alias)
        ->state(['url' => '/alias'])
        ->create();
    $page = $page->fresh(['pageUrl', 'pageUrls.siteDomain']);
    $page->setRelation('pageUrl', $alias->setRelation('siteDomain', $site->siteDomains->firstWhere('language_id', $language->id)));

    expect(ResolvePageCanonicalUrlAction::run($page, $language))
        ->toContain('/canonical');
});
