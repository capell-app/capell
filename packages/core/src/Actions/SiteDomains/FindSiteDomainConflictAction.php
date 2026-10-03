<?php

declare(strict_types=1);

namespace Capell\Core\Actions\SiteDomains;

use Capell\Core\Models\SiteDomain;
use Illuminate\Database\Eloquent\Builder;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/** Domain uniqueness is a system invariant, including sites hidden from the actor. */
final class FindSiteDomainConflictAction
{
    use AsFake;
    use AsObject;

    /** @param array<string, string|null> $urlParts */
    public function handle(array $urlParts, ?SiteDomain $record = null): ?SiteDomain
    {
        $scheme = $urlParts['scheme'] ?? null;
        $host = $urlParts['host'] ?? null;
        $appUrlHost = parse_url((string) config('app.url'), PHP_URL_HOST);

        return SiteDomain::query()
            ->when($scheme !== null, fn (Builder $query): Builder => $query->where(fn (Builder $schemes): Builder => $schemes->whereNull('scheme')->orWhere('scheme', $scheme)))
            ->when($host === null, fn (Builder $query): Builder => $query->whereNull('domain'), fn (Builder $query): Builder => $query->where(fn (Builder $hosts): Builder => $hosts->where('domain', $host)->when($host === $appUrlHost, fn (Builder $hosts): Builder => $hosts->orWhereNull('domain'))))
            ->where('path', $urlParts['path'] ?? null)
            ->withWhereHas('site')
            ->when($record instanceof SiteDomain, fn (Builder $query): Builder => $query->whereKeyNot($record?->id))
            ->first();
    }
}
