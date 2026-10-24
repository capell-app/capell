<?php

declare(strict_types=1);

namespace Capell\Core\Actions\SiteDomains;

use Capell\Core\Models\SiteDomain;
use Capell\Core\Support\Permissions\SiteAccess;
use Capell\Core\Support\SiteDomains\SiteDomainAddressing;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/** Scoped actors cannot partition an origin owned by an inaccessible site. */
final class FindInaccessibleSiteDomainClaimAction
{
    use AsFake;
    use AsObject;

    /** @param array<string, int|string|null> $parts */
    public function handle(Authenticatable $actor, array $parts, ?SiteDomain $record = null): ?SiteDomain
    {
        $access = SiteAccess::forActor($actor);
        if ($access->isGlobal()) {
            return null;
        }

        $scheme = SiteDomainAddressing::normalizeScheme($parts['scheme'] ?? null);
        $host = SiteDomainAddressing::normalizeHost($parts['host'] ?? null);
        $port = SiteDomainAddressing::normalizeStoredPort($scheme, $parts['port'] ?? null);

        $recordKey = $record?->exists === true ? $record->getKey() : null;

        $candidates = ($record?->newQuery() ?? SiteDomain::query())
            ->whereNotIn('site_id', $access->allowedSiteIds() ?? [])
            ->when($recordKey !== null, fn (Builder $query): Builder => $query->whereKeyNot($recordKey))
            ->withWhereHas('site')
            // A locking read sees claims committed while waiting, including under MySQL REPEATABLE READ.
            ->lockForUpdate()
            ->get();

        foreach ($candidates as $candidate) {
            // Public resolution normalises legacy stored values too; raw SQL equality
            // would miss equivalent origins such as trailing-dot or mixed-case hosts.
            $candidateHost = $candidate->getRawOriginal('domain');
            if ($host !== null && $candidateHost !== null && SiteDomainAddressing::normalizeHost($candidateHost) !== $host) {
                continue;
            }

            $candidateScheme = SiteDomainAddressing::normalizeScheme($candidate->getRawOriginal('scheme'));
            foreach ($scheme === null ? ['http', 'https'] : [$scheme] as $possibleScheme) {
                if ($candidateScheme !== null && $candidateScheme !== $possibleScheme) {
                    continue;
                }

                $candidatePort = $candidate->getRawOriginal('port');
                if (SiteDomainAddressing::effectivePort($possibleScheme, $port) === SiteDomainAddressing::effectivePort($possibleScheme, $candidatePort)) {
                    return $candidate;
                }
            }
        }

        return null;
    }
}
