<?php

declare(strict_types=1);

namespace Capell\Core\Actions\SiteDomains;

use Capell\Core\Data\SiteDomains\SiteDomainResolutionData;
use Capell\Core\Data\SiteDomains\SiteRequestTargetData;
use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;
use Capell\Core\Support\SiteDomains\SiteDomainAddressing;
use Illuminate\Support\Collection;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * @method static SiteDomainResolutionData|null run(SiteRequestTargetData $target, Collection<int, Site> $sites)
 */
final class ResolveSiteDomainAction
{
    use AsFake;
    use AsObject;

    /**
     * @param  Collection<int, Site>  $sites
     */
    public function handle(SiteRequestTargetData $target, Collection $sites): ?SiteDomainResolutionData
    {
        $domains = $sites
            ->flatMap(fn (Site $site): array => $site->siteDomains->all())
            ->filter(fn (mixed $domain): bool => $domain instanceof SiteDomain)
            ->values();

        /** @var Collection<int, SiteDomain> $domains */
        $enabledExact = $domains
            ->filter(fn (SiteDomain $domain): bool => $domain->status
                && $this->constraintAttribute($domain, 'domain') !== null
                && $this->matchesOrigin($domain, $target))
            ->values();

        /** @var Collection<int, SiteDomain> $enabledWildcards */
        $enabledWildcards = $domains
            ->filter(fn (SiteDomain $domain): bool => $domain->status
                && $this->constraintAttribute($domain, 'domain') === null
                && $this->matchesOrigin($domain, $target))
            ->values();

        $preferredWildcards = $this->preferredWildcards($enabledExact, $enabledWildcards);

        $best = $this->bestMountedPath($enabledExact, $target->path)
            ?? $this->bestMountedPath($preferredWildcards, $target->path);

        if ($best instanceof SiteDomain) {
            return $this->resolution($best, $target);
        }

        $hasExactDisabledPath = $domains->contains(function (SiteDomain $domain) use ($target): bool {
            if ($domain->status || $this->constraintAttribute($domain, 'domain') === null || ! $this->matchesOrigin($domain, $target)) {
                return false;
            }

            $mountedPath = SiteDomainAddressing::normalizePath($domain->path);

            return $mountedPath !== '/' && $mountedPath === $target->path;
        });

        if ($hasExactDisabledPath) {
            return null;
        }

        $root = $this->rootDomain($enabledExact) ?? $this->rootDomain($preferredWildcards);

        return $root instanceof SiteDomain ? $this->resolution($root, $target) : null;
    }

    private function matchesOrigin(SiteDomain $domain, SiteRequestTargetData $target): bool
    {
        $rawHost = $this->constraintAttribute($domain, 'domain');
        if ($rawHost !== null && SiteDomainAddressing::normalizeHost($rawHost) !== $target->origin->host) {
            return false;
        }

        $rawScheme = SiteDomainAddressing::normalizeScheme($this->constraintAttribute($domain, 'scheme'));
        if ($rawScheme !== null && $rawScheme !== $target->origin->scheme) {
            return false;
        }

        $rawPort = $this->constraintAttribute($domain, 'port');
        if (! in_array($rawPort, [null, false, ''], true)) {
            return (int) $rawPort === $target->origin->effectivePort;
        }

        return SiteDomainAddressing::defaultPort($target->origin->scheme) === $target->origin->effectivePort;
    }

    /**
     * @param  Collection<int, SiteDomain>  $exact
     * @param  Collection<int, SiteDomain>  $wildcards
     * @return Collection<int, SiteDomain>
     */
    private function preferredWildcards(Collection $exact, Collection $wildcards): Collection
    {
        if ($exact->isEmpty()) {
            return $this->sorted($wildcards);
        }

        $siteIds = $exact->pluck('site_id')->unique()->all();
        $sameSite = $wildcards
            ->filter(fn (SiteDomain $domain): bool => in_array($domain->site_id, $siteIds, true))
            ->values();

        return $this->sorted($sameSite->isEmpty() ? $wildcards : $sameSite);
    }

    /**
     * @param  Collection<int, SiteDomain>  $domains
     */
    private function bestMountedPath(Collection $domains, string $requestPath): ?SiteDomain
    {
        return $this->sorted($domains)
            ->filter(function (SiteDomain $domain) use ($requestPath): bool {
                $mountedPath = SiteDomainAddressing::normalizePath($domain->path);

                return $mountedPath !== '/' && $this->pathMatches($requestPath, $mountedPath);
            })
            ->sortByDesc(fn (SiteDomain $domain): int => strlen(SiteDomainAddressing::normalizePath($domain->path)))
            ->first();
    }

    /**
     * @param  Collection<int, SiteDomain>  $domains
     */
    private function rootDomain(Collection $domains): ?SiteDomain
    {
        return $this->sorted($domains)
            ->first(fn (SiteDomain $domain): bool => SiteDomainAddressing::normalizePath($domain->path) === '/');
    }

    /**
     * @param  Collection<int, SiteDomain>  $domains
     * @return Collection<int, SiteDomain>
     */
    private function sorted(Collection $domains): Collection
    {
        return $domains->sort(function (SiteDomain $left, SiteDomain $right): int {
            $leftScheme = $this->constraintAttribute($left, 'scheme') === null ? 0 : 1;
            $rightScheme = $this->constraintAttribute($right, 'scheme') === null ? 0 : 1;
            $schemeOrder = $rightScheme <=> $leftScheme;

            if ($schemeOrder !== 0) {
                return $schemeOrder;
            }

            $leftPort = $this->constraintAttribute($left, 'port') === null ? 0 : 1;
            $rightPort = $this->constraintAttribute($right, 'port') === null ? 0 : 1;
            $portOrder = $rightPort <=> $leftPort;

            if ($portOrder !== 0) {
                return $portOrder;
            }

            return ((int) ($left->getKey() ?? PHP_INT_MAX)) <=> ((int) ($right->getKey() ?? PHP_INT_MAX));
        })->values();
    }

    private function pathMatches(string $requestPath, string $mountedPath): bool
    {
        return $requestPath === $mountedPath || str_starts_with($requestPath, $mountedPath . '/');
    }

    private function constraintAttribute(SiteDomain $domain, string $attribute): mixed
    {
        if ($domain->exists) {
            return $domain->getRawOriginal($attribute);
        }

        return $domain->getAttributes()[$attribute] ?? null;
    }

    private function resolution(SiteDomain $domain, SiteRequestTargetData $target): SiteDomainResolutionData
    {
        $mountedPath = SiteDomainAddressing::normalizePath($domain->path);
        $relativePath = $mountedPath === '/'
            ? $target->path
            : substr($target->path, strlen($mountedPath));

        if ($relativePath === '') {
            $relativePath = '/';
        } else {
            $relativePath = SiteDomainAddressing::normalizePath($relativePath);
        }

        return new SiteDomainResolutionData(
            siteDomain: $domain,
            effectiveOrigin: $target->origin,
            mountedPath: $mountedPath,
            relativePath: $relativePath,
        );
    }
}
