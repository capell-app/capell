<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Cache;

use Capell\Core\Models\Language;
use Capell\Core\Models\Site;
use Capell\Frontend\Support\Loader\SiteLoader;

final class LazyLoadedSiteContext
{
    private ?Site $fullyLoadedSite = null;

    public function __construct(
        private readonly Site $minimalSite,
        private readonly Language $language,
    ) {}

    public function site(): Site
    {
        if (! $this->fullyLoadedSite instanceof Site) {
            $this->fullyLoadedSite = SiteLoader::loadSite($this->minimalSite, $this->language);
        }

        return $this->fullyLoadedSite ?? $this->minimalSite;
    }

    public function language(): Language
    {
        return $this->language;
    }

    public function isFullyLoaded(): bool
    {
        return $this->fullyLoadedSite instanceof Site;
    }

    public function preloadSite(): void
    {
        $this->site();
    }
}
