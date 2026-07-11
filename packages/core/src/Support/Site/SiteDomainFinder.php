<?php

declare(strict_types=1);

namespace Capell\Core\Support\Site;

use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;
use Illuminate\Support\Collection;

final class SiteDomainFinder
{
    /**
     * @param  Collection<int, Site>  $sites
     */
    public static function firstEnabledDefault(Collection $sites): ?SiteDomain
    {
        foreach ($sites as $site) {
            $match = $site->siteDomains->first(
                fn (SiteDomain $domain): bool => $domain->default && $domain->status,
            );
            if ($match instanceof SiteDomain) {
                return $match;
            }
        }

        return null;
    }
}
