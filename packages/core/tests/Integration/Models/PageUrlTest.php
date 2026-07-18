<?php

declare(strict_types=1);

use Capell\Core\Actions\DiagnosePageUrlSiteDomainAction;
use Capell\Core\Actions\FindPageUrlsMissingSiteDomainsAction;
use Capell\Core\Actions\RepairPageUrlsMissingSiteDomainsAction;
use Capell\Core\Enums\UrlTypeEnum;
use Capell\Core\Exceptions\UrlMissingSiteDomainException;
use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;
use Capell\Core\Models\Translation;
use Illuminate\Support\Facades\Log;

it('keeps active redirect scope SQL equivalent to the explicit predicates', function (): void {
    $scoped = PageUrl::query()->activeRedirects();
    $explicit = PageUrl::query()
        ->where('type', UrlTypeEnum::Redirect)
        ->where('status', true);

    $normalizedScopedSql = str_replace('"page_urls"."status"', '"status"', $scoped->toSql());

    expect($normalizedScopedSql)->toBe($explicit->toSql())
        ->and($scoped->getBindings())->toBe($explicit->getBindings());
});

it('belongs to a site', function (): void {
    Site::factory()->createOne();
    $site = Site::factory()->createOne();
    $pageUrl = PageUrl::factory()->createOne(['site_id' => $site->id]);

    expect($pageUrl->site)->toBeInstanceOf(Site::class)
        ->id->toBe($site->id);
});

it('belongs to a language', function (): void {
    Language::factory()->createOne();
    $language = Language::factory()->createOne();
    $pageUrl = PageUrl::factory()->createOne(['language_id' => $language->id]);

    expect($pageUrl->language)->toBeInstanceOf(Language::class)
        ->id->toBe($language->id);
});

it('belongs to a page', function (): void {
    $page = Page::factory()->createOne();
    $pageUrl = PageUrl::factory()->site($page->site)->page($page)->createOne();

    expect($pageUrl->pageable)->toBeInstanceOf(Page::class)
        ->id->toBe($page->id);
});

it('has one translation', function (): void {
    $page = Page::factory()->createOne();
    $pageUrl = PageUrl::factory()->site($page->site)->page($page)->create();
    Translation::factory()->translatable($page)->language($pageUrl->language)->create();

    expect($pageUrl)->toBeInstanceOf(PageUrl::class)
        ->and($pageUrl->translation)->toBeInstanceOf(Translation::class)
        ->language_id->toBe($pageUrl->language_id);
});

it('eager loads the page relation', function (): void {
    $page = Page::factory()->createOne();
    $pageUrl = PageUrl::factory()->site($page->site)->page($page)->create();

    $found = PageUrl::with('pageable')->find($pageUrl->id);
    assert($found instanceof PageUrl);

    expect($found)->relationLoaded('pageable')->toBeTrue()
        ->and($found->pageable)
        ->toBeInstanceOf(Page::class)
        ->id->toBe($page->id);
});

it('has a url attribute', function (): void {
    $pageUrl = PageUrl::factory()->createOne(['url' => '/test']);

    expect($pageUrl->url)->toBe('/test');
});

it('has a full url attribute', function (): void {
    $siteDomain = SiteDomain::factory()->createOne(['domain' => 'example.com', 'path' => null, 'scheme' => 'https']);

    $pageUrl = PageUrl::factory()
        ->recycle($siteDomain->site)
        ->recycle($siteDomain->language)
        ->create([
            'url' => '/test',
        ]);

    expect($pageUrl->full_url)->toBe('https://example.com/test');
});

it('diagnoses a missing active site domain for a page url', function (): void {
    $siteDomain = SiteDomain::factory()->createOne(['domain' => 'example.com', 'path' => null, 'scheme' => 'https']);

    $pageUrl = PageUrl::factory()
        ->recycle($siteDomain->site)
        ->recycle($siteDomain->language)
        ->create([
            'url' => '/test',
        ]);

    $siteDomain->delete();
    $pageUrl->setRelation('siteDomain', null);

    $diagnostic = DiagnosePageUrlSiteDomainAction::run($pageUrl, 'TestCaller::handle');

    expect($diagnostic->toLogContext())
        ->toMatchArray([
            'page_url_id' => $pageUrl->getKey(),
            'site_id' => $siteDomain->site_id,
            'language_id' => $siteDomain->language_id,
            'site_domain_relation_loaded' => true,
            'loaded_site_domain_is_null' => true,
            'active_site_domain_exists' => false,
            'active_site_domain_id' => null,
            'trashed_site_domain_exists' => true,
            'trashed_site_domain_id' => $siteDomain->getKey(),
            'caller' => 'TestCaller::handle',
        ])
        ->and($diagnostic->trashedSiteDomainDeletedAt)->not->toBeNull();
});

it('finds and repairs page urls missing active site domains', function (): void {
    $siteDomain = SiteDomain::factory()->createOne(['domain' => 'example.com', 'path' => null, 'scheme' => 'https']);

    $pageUrl = PageUrl::factory()
        ->recycle($siteDomain->site)
        ->recycle($siteDomain->language)
        ->create([
            'url' => '/test',
        ]);

    $siteDomain->delete();

    expect(FindPageUrlsMissingSiteDomainsAction::run()->modelKeys())->toContain($pageUrl->getKey());

    $repaired = RepairPageUrlsMissingSiteDomainsAction::run();
    $pageUrl->refresh()->unsetRelation('siteDomain');
    $restoredSiteDomain = SiteDomain::query()
        ->withTrashed()
        ->whereKey($siteDomain->id)
        ->firstOrFail();

    expect($repaired)->toBe(1)
        ->and(FindPageUrlsMissingSiteDomainsAction::run())->toBeEmpty()
        ->and($pageUrl->full_url)->toBe('https://example.com/test')
        ->and($restoredSiteDomain->trashed())->toBeFalse();
});

it('logs page url site domain diagnostics before throwing when enabled', function (): void {
    config()->set('capell.debug.relationship_diagnostics', true);
    Log::spy();

    $siteDomain = SiteDomain::factory()->createOne(['domain' => 'example.com', 'path' => null, 'scheme' => 'https']);

    $pageUrl = PageUrl::factory()
        ->recycle($siteDomain->site)
        ->recycle($siteDomain->language)
        ->create([
            'url' => '/test',
        ]);

    $siteDomain->delete();
    $pageUrl->setRelation('siteDomain', null);

    expect(fn (): string => $pageUrl->full_url)
        ->toThrow(UrlMissingSiteDomainException::class);

    Log::getFacadeRoot()->shouldHaveReceived('warning')
        ->once()
        ->with(
            'Capell PageUrl full_url could not resolve an active site domain.',
            Mockery::on(fn (array $context): bool => $context['page_url_id'] === $pageUrl->getKey()
                && $context['site_id'] === $siteDomain->site_id
                && $context['language_id'] === $siteDomain->language_id
                && $context['loaded_site_domain_is_null'] === true
                && $context['active_site_domain_exists'] === false
                && $context['trashed_site_domain_id'] === $siteDomain->getKey()),
        );
});

it('can load a Url by url, site domain, and language', function (): void {
    Site::factory()->createOne();
    $siteDomain = SiteDomain::factory()->createOne();
    $site = $siteDomain->site;
    $language = $siteDomain->language;

    $pageUrl = PageUrl::factory()->createOne([
        'url' => '/load-by-url-test',
        'site_id' => $site->id,
        'language_id' => $language->id,
    ]);

    $found = PageUrl::loadByUrl('/load-by-url-test', $siteDomain, $language);
    $found = expectPresent($found);

    expect($found)
        ->toBeInstanceOf(PageUrl::class)
        ->and($found->id)->toBe($pageUrl->id);

    $notFound = PageUrl::loadByUrl('/does-not-exist', $siteDomain, $language);
    expect($notFound)->toBeNull();
});
