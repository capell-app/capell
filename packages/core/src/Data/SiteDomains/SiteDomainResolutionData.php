<?php

declare(strict_types=1);

namespace Capell\Core\Data\SiteDomains;

use Capell\Core\Models\SiteDomain;
use Capell\Core\Support\SiteDomains\SiteDomainAddressing;

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

    public function siteDomainForRequest(): SiteDomain
    {
        $siteDomain = $this->siteDomain;
        $configuredDomain = $siteDomain->exists
            ? $siteDomain->getRawOriginal('domain')
            : ($siteDomain->getAttributes()['domain'] ?? null);

        if ($configuredDomain !== null) {
            return $siteDomain;
        }

        $siteDomain = clone $siteDomain;
        $siteDomain->setAttribute('domain', $this->effectiveOrigin->host);

        $configuredScheme = $siteDomain->exists
            ? $siteDomain->getRawOriginal('scheme')
            : ($siteDomain->getAttributes()['scheme'] ?? null);

        if ($configuredScheme === null) {
            $siteDomain->setAttribute('scheme', $this->effectiveOrigin->scheme);
        }

        $defaultPort = SiteDomainAddressing::defaultPort($this->effectiveOrigin->scheme);
        $siteDomain->setAttribute(
            'port',
            $defaultPort === $this->effectiveOrigin->effectivePort
                ? null
                : $this->effectiveOrigin->effectivePort,
        );

        return $siteDomain;
    }
}
