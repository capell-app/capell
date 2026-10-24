<?php

declare(strict_types=1);

namespace Capell\Core\Actions\SiteDomains;

use Capell\Core\Models\SiteDomain;
use Capell\Core\Support\SiteDomains\SiteDomainAddressing;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/** Domain uniqueness is a system invariant, including sites hidden from the actor. */
final class FindSiteDomainConflictAction
{
    use AsFake;
    use AsObject;

    /** @param array<string, int|string|null> $urlParts */
    public function handle(array $urlParts, ?SiteDomain $record = null): ?SiteDomain
    {
        $actor = auth()->user();
        if ($actor instanceof Authenticatable) {
            $claim = FindInaccessibleSiteDomainClaimAction::run($actor, $urlParts, $record);
            if ($claim instanceof SiteDomain) {
                return $claim;
            }
        }

        $scheme = SiteDomainAddressing::normalizeScheme($urlParts['scheme'] ?? null);
        $host = SiteDomainAddressing::normalizeHost($urlParts['host'] ?? null);
        $appUrlHost = SiteDomainAddressing::normalizeHost(parse_url((string) config('app.url'), PHP_URL_HOST));

        return SiteDomain::query()
            ->when($scheme !== null, fn (Builder $query): Builder => $query->where(fn (Builder $schemes): Builder => $schemes->whereNull('scheme')->orWhere('scheme', $scheme)))
            ->when($host === null, fn (Builder $query): Builder => $query->whereNull('domain'), fn (Builder $query): Builder => $query->where(fn (Builder $hosts): Builder => $hosts->where('domain', $host)->when($host === $appUrlHost, fn (Builder $hosts): Builder => $hosts->orWhereNull('domain'))))
            ->where('path', $urlParts['path'] ?? null)
            ->withWhereHas('site')
            ->when($record instanceof SiteDomain, fn (Builder $query): Builder => $query->whereKeyNot($record?->id))
            ->first();
    }
}
