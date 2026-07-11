<?php

declare(strict_types=1);

use Capell\Core\Exceptions\SiteDomainNotFoundException;
use Capell\Core\Exceptions\UrlSiteDomainNotFoundException;
use Capell\Core\Models\Language;
use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;
use Capell\Frontend\Support\Loader\SiteResolver;
use Illuminate\Support\Collection;

it('resolves site, language, sitedomain, and url', function (): void {
    $language = Language::factory()->createOne();
    $site = Site::factory()->hasTranslations(['language_id' => $language->id])->create();
    $siteDomain = SiteDomain::factory()->createOne(['site_id' => $site->id, 'language_id' => $language->id]);
    $sites = Site::query()->get();
    $url = '/foo/bar';

    // Simulate a real URL that matches the created SiteDomain
    $fullUrl = $siteDomain->full_url . $url;

    [$resolvedSite, $resolvedLanguage, $resolvedSiteDomain, $normalizedUrl] = SiteResolver::resolve($fullUrl, $sites);

    expect($resolvedSite->id)->toBe($site->id)
        ->and($resolvedLanguage->id)->toBe($language->id)
        ->and($resolvedSiteDomain->id)->toBe($siteDomain->id)
        ->and($normalizedUrl)->toBe($url);
});

it('resolves a null domain using the request host and matching scheme path', function (): void {
    $language = Language::factory()->createOne();
    $site = Site::factory()->hasTranslations(['language_id' => $language->id])->create();
    $siteDomain = SiteDomain::factory()->createOne([
        'site_id' => $site->id,
        'language_id' => $language->id,
        'domain' => null,
        'scheme' => 'https',
        'path' => '/tenant',
    ]);
    $sites = Site::query()->with('siteDomains', 'translations')->get();

    [$resolvedSite, $resolvedLanguage, $resolvedSiteDomain, $normalizedUrl] = SiteResolver::resolve(
        'https://customer.example.test/tenant/about',
        $sites,
    );

    expect($resolvedSite->id)->toBe($site->id)
        ->and($resolvedLanguage->id)->toBe($language->id)
        ->and($resolvedSiteDomain->id)->toBe($siteDomain->id)
        ->and($resolvedSiteDomain->domain)->toBe('customer.example.test')
        ->and($normalizedUrl)->toBe('/about');
});

it('throws SiteDomainNotFoundException if sites collection is empty', function (): void {
    $sites = new Collection;
    expect(fn (): array => SiteResolver::resolve('https://example.com', $sites))
        ->toThrow(SiteDomainNotFoundException::class, 'No sites are configured.');
});

it('throws if no siteDomain found', function (): void {
    $language = Language::factory()->createOne();
    $site = Site::factory()->createOne();
    $sites = Site::query()->get();
    $url = '/not-matching';
    $fullUrl = 'https://not-a-real-domain.com' . $url;

    expect(fn (): array => SiteResolver::resolve($fullUrl, $sites))
        ->toThrow(UrlSiteDomainNotFoundException::class);
});

it('throws if no translation found', function (): void {
    $language = Language::factory()->createOne();
    $site = Site::factory()->createOne();
    $siteDomain = SiteDomain::factory()->createOne(['site_id' => $site->id, 'language_id' => $language->id]);
    $sites = Site::query()->get();
    $url = '/foo';
    $fullUrl = $siteDomain->full_url . $url;

    // Remove all translations from the site
    $site->translations()->delete();

    expect(fn (): array => SiteResolver::resolve($fullUrl, $sites))
        ->toThrow(Exception::class, 'No translation found');
});
