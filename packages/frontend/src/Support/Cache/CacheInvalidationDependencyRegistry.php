<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Cache;

use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;

final class CacheInvalidationDependencyRegistry
{
    /** @var array<class-string|string, list<string>> */
    private array $modelDependencies = [
        // Site-level data is rendered into every page — the site name reaches the
        // page title through the meta separator, and the site's theme and settings
        // shape the whole document. Without page-* a site rename leaves every
        // cached page showing the old one, which is why Language already lists it.
        Site::class => ['sites', 'site-*', 'site-related-*', 'page-*'],
        Language::class => ['languages', 'page-*', 'site-*'],
        Page::class => ['pages', 'page-*', 'homepage-*', 'page-error-*'],
        'Capell\Core\Models\Navigation' => ['navigation-*', 'site-navigations-*'],
        // Generated URLs embed the domain when use_site_domain_for_urls is on, so a
        // domain change leaves stale absolute URLs baked into cached pages.
        SiteDomain::class => ['sites', 'site-*', 'page-*'],
    ];

    /** @param string|array<string> $cachePatterns */
    public function register(string $modelClass, string|array $cachePatterns): void
    {
        $patterns = is_array($cachePatterns) ? array_values($cachePatterns) : [$cachePatterns];
        $this->modelDependencies[$modelClass] = array_merge(
            $this->modelDependencies[$modelClass] ?? [],
            $patterns,
        );
    }

    /** @return list<string> */
    public function patternsFor(string $modelClass): array
    {
        return $this->modelDependencies[$modelClass] ?? [];
    }

    /** @return list<string> */
    public function allPatterns(): array
    {
        return array_values(array_unique(array_merge(...array_values($this->modelDependencies))));
    }
}
