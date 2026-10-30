<?php

declare(strict_types=1);

use Capell\Core\Models\Language;
use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;
use Capell\Frontend\Support\Loader\SiteLoader;
use Capell\Frontend\Support\Loader\SiteResolver;

it('resolves site, language, domain, and normalized path', function (): void {
    $site = Site::factory()->withTranslations()->create();
    $domain = $site->siteDomains->first();
    $full = ($domain->scheme ?? 'https') . '://' . $domain->domain . ($domain->path ?? '/');

    [$resolvedSite, $language, $resolvedDomain, $normalizedPath] = SiteResolver::resolve($full, SiteLoader::getSites());

    expect($resolvedSite->id)->toBe($site->id)
        ->and($language->id)->toBe($site->language->id)
        ->and($resolvedDomain->id)->toBe($domain->id)
        ->and($normalizedPath)->toBe('/');
});

it('resolves a domain assigned to a language the site had no translation for', function (): void {
    $site = Site::factory()->withTranslations()->create();
    $french = Language::factory()->createOne(['code' => 'fr']);

    expect($site->translations()->where('language_id', $french->getKey())->exists())->toBeFalse();

    SiteDomain::factory()->createOne([
        'site_id' => $site->getKey(),
        'language_id' => $french->getKey(),
        'domain' => 'fr.example.test',
        'scheme' => 'https',
        'path' => null,
        'default' => false,
    ]);

    [$resolvedSite, $language] = SiteResolver::resolve('https://fr.example.test/', SiteLoader::getSites());

    expect($resolvedSite->getKey())->toBe($site->getKey())
        ->and($language->getKey())->toBe($french->getKey())
        ->and($site->translations()->where('language_id', $french->getKey())->count())->toBe(1);
});
