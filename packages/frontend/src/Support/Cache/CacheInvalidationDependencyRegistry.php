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
        Site::class => ['sites', 'site-*', 'site-related-*'],
        Language::class => ['languages', 'page-*', 'site-*'],
        Page::class => ['pages', 'page-*', 'homepage-*', 'page-error-*'],
        'Capell\Core\Models\Navigation' => ['navigation-*', 'site-navigations-*'],
        SiteDomain::class => ['sites', 'site-*'],
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
}
