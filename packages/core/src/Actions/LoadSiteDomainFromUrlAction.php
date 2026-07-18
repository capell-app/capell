<?php

declare(strict_types=1);

namespace Capell\Core\Actions;

use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;
use Capell\Core\Support\Url\UrlPathNormalizer;
use Illuminate\Support\Collection;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * @method static array{0: SiteDomain, 1: string}|null run(string $url, ?Collection<int, Site> $sites = null)
 */
final class LoadSiteDomainFromUrlAction
{
    use AsFake;
    use AsObject;

    /**
     * @param  Collection<int, Site>|null  $sites
     * @return array{0: SiteDomain, 1: string}|null
     */
    public function handle(string $url, ?Collection $sites = null): ?array
    {
        $urlParts = parse_url($url);
        if (! isset($urlParts['host'])) {
            return null;
        }

        $host = $urlParts['host'];
        $path = $this->normalizePath($urlParts['path'] ?? '');
        $scheme = $urlParts['scheme'] ?? 'https';

        $path = $this->normalizePath(UrlPathNormalizer::stripIndexPhp($path));

        if (! $sites instanceof Collection) {
            $sites = Site::query()->excludingPreview()->with('siteDomains')->get();
        }

        $enabledForHost = $this->getEnabledDomainsForHost($sites, $host, $scheme);
        $enabledWildcardDomains = $this->getEnabledWildcardDomains($sites, $scheme);
        $preferredWildcardDomains = $this->getPreferredWildcardDomains($enabledForHost, $enabledWildcardDomains);

        if ($enabledForHost->isEmpty() && $enabledWildcardDomains->isEmpty()) {
            return null;
        }

        $best = $this->findBestEnabledPrefixMatch($enabledForHost, $path);
        if (! $best instanceof SiteDomain) {
            $best = $this->findBestEnabledPrefixMatch($preferredWildcardDomains, $path);
        }

        if ($best instanceof SiteDomain) {
            $this->applyRequestOriginToWildcardDomain($best, $host, $scheme);

            $domainPath = $this->normalizePath($best->path ?? '/');
            $remaining = $this->remainingPath($path, $domainPath);

            return [$best, $remaining];
        }

        $allForHost = $this->getAllDomainsForHost($sites, $host);
        $hasExactDisabled = $allForHost->contains(function (SiteDomain $siteDomain) use ($path): bool {
            $domainPath = $this->normalizePath($siteDomain->path ?? '/');
            if ($domainPath === '/' || $siteDomain->status) {
                return false;
            }

            return $domainPath === $path;
        });
        if ($hasExactDisabled) {
            return null;
        }

        $root = $enabledForHost->first(function (SiteDomain $siteDomain): bool {
            $domainPath = $this->normalizePath($siteDomain->path ?? '/');

            return $domainPath === '/';
        });
        if (! $root instanceof SiteDomain) {
            $root = $preferredWildcardDomains->first(function (SiteDomain $siteDomain): bool {
                $domainPath = $this->normalizePath($siteDomain->path ?? '/');

                return $domainPath === '/';
            });
        }

        if (! $root instanceof SiteDomain) {
            return null;
        }

        $this->applyRequestOriginToWildcardDomain($root, $host, $scheme);

        return [$root, $path === '' ? '/' : $path];
    }

    private function normalizePath(string $path): string
    {
        if ($path === '') {
            return '/';
        }

        $normalizedPath = '/' . ltrim($path, '/');

        return $normalizedPath !== '/' ? rtrim($normalizedPath, '/') : $normalizedPath;
    }

    private function remainingPath(string $full, string $prefix): string
    {
        if ($prefix === '/' || $prefix === '') {
            return $full === '' ? '/' : $full;
        }

        $prefixLength = strlen($prefix);
        $remainingPath = substr($full, $prefixLength);
        if ($remainingPath === '') {
            return '/';
        }

        return $this->normalizePath($remainingPath);
    }

    private function isPrefixMatch(string $full, string $prefix): bool
    {
        if ($prefix === '/' || $prefix === '') {
            return true;
        }

        if (! str_starts_with($full, $prefix)) {
            return false;
        }

        if ($full === $prefix) {
            return true;
        }

        $next = $full[strlen($prefix)] ?? '/';

        return $next === '/';
    }

    /** @param Collection<int, SiteDomain> $enabledForHost */
    private function findBestEnabledPrefixMatch(Collection $enabledForHost, string $path): ?SiteDomain
    {
        $best = null;
        $bestLength = -1;
        foreach ($enabledForHost as $domain) {
            $domainPath = $this->normalizePath((string) ($domain->path ?? '/'));
            if ($domainPath === '/') {
                continue;
            }

            if ($this->isPrefixMatch($path, $domainPath)) {
                $length = strlen($domainPath);
                if ($length > $bestLength) {
                    $best = $domain;
                    $bestLength = $length;
                }
            }
        }

        return $best;
    }

    /**
     * @param  Collection<int, Site>  $sites
     * @return Collection<int, SiteDomain>
     */
    private function getEnabledDomainsForHost(Collection $sites, string $host, ?string $scheme): Collection
    {
        $enumerable = $sites->flatMap(fn (Site $site): array => $site->siteDomains->all())
            ->filter(function (SiteDomain $siteDomain) use ($host, $scheme): bool {
                if ($siteDomain->domain !== $host) {
                    return false;
                }

                if (! $siteDomain->status) {
                    return false;
                }

                if ($siteDomain->getRawOriginal('scheme') === null || $siteDomain->getRawOriginal('scheme') === false) {
                    return true;
                }

                return $siteDomain->scheme === $scheme;
            })
            ->values();

        return collect($enumerable);
    }

    /**
     * @param  Collection<int, Site>  $sites
     * @return Collection<int, SiteDomain>
     */
    private function getEnabledWildcardDomains(Collection $sites, ?string $scheme): Collection
    {
        $enumerable = $sites->flatMap(fn (Site $site): array => $site->siteDomains->all())
            ->filter(function (SiteDomain $siteDomain) use ($scheme): bool {
                if ($siteDomain->domain !== null) {
                    return false;
                }

                if (! $siteDomain->status) {
                    return false;
                }

                if ($siteDomain->getRawOriginal('scheme') === null || $siteDomain->getRawOriginal('scheme') === false) {
                    return true;
                }

                return $siteDomain->scheme === $scheme;
            })
            ->values();

        return collect($enumerable);
    }

    /**
     * @param  Collection<int, SiteDomain>  $enabledForHost
     * @param  Collection<int, SiteDomain>  $enabledWildcardDomains
     * @return Collection<int, SiteDomain>
     */
    private function getPreferredWildcardDomains(Collection $enabledForHost, Collection $enabledWildcardDomains): Collection
    {
        if ($enabledForHost->isEmpty()) {
            return $enabledWildcardDomains;
        }

        $hostSiteIds = $enabledForHost
            ->pluck('site_id')
            ->unique()
            ->all();

        $sameSiteWildcardDomains = $enabledWildcardDomains
            ->filter(fn (SiteDomain $siteDomain): bool => in_array($siteDomain->site_id, $hostSiteIds, true))
            ->values();

        if ($sameSiteWildcardDomains->isEmpty()) {
            return $enabledWildcardDomains;
        }

        return $sameSiteWildcardDomains;
    }

    /**
     * @param  Collection<int, Site>  $sites
     * @return Collection<int, SiteDomain>
     */
    private function getAllDomainsForHost(Collection $sites, string $host): Collection
    {
        $enumerable = $sites->flatMap(fn (Site $site): array => $site->siteDomains->all())
            ->filter(fn (SiteDomain $siteDomain): bool => $siteDomain->domain === $host)
            ->values();

        return collect($enumerable);
    }

    private function applyRequestOriginToWildcardDomain(SiteDomain $siteDomain, string $host, string $scheme): void
    {
        if ($siteDomain->getRawOriginal('domain') !== null) {
            return;
        }

        $siteDomain->setAttribute('domain', $host);

        if ($siteDomain->getRawOriginal('scheme') === null || $siteDomain->getRawOriginal('scheme') === false) {
            $siteDomain->setAttribute('scheme', $scheme);
        }
    }
}
