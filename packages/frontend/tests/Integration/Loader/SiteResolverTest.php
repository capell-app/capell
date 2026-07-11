<?php

declare(strict_types=1);

use Capell\Core\Models\Site;
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
