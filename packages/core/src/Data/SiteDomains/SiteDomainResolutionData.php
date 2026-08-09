<?php

declare(strict_types=1);

namespace Capell\Core\Data\SiteDomains;

use Capell\Core\Models\SiteDomain;

final readonly class SiteDomainResolutionData
{
    public function __construct(
        public SiteDomain $siteDomain,
        public SiteOriginData $effectiveOrigin,
        public string $mountedPath,
        public string $relativePath,
    ) {}

    public function rootUrl(): string
    {
        return $this->effectiveOrigin->rootUrl();
    }

    public function baseUrl(): string
    {
        return $this->rootUrl() . ($this->mountedPath === '/' ? '' : $this->mountedPath);
    }

    public function urlFor(string $path): string
    {
        $path = $path === '/' ? '' : '/' . ltrim($path, '/');

        return $this->baseUrl() . $path;
    }

    public function domainKey(): string
    {
        return $this->effectiveOrigin->key() . ':' . hash('sha256', $this->mountedPath);
    }
}
