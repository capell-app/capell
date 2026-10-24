<?php

declare(strict_types=1);

namespace Capell\Core\Actions\SiteDomains;

use Capell\Core\Models\SiteDomain;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class AssertSiteDomainClaimAllowedAction
{
    use AsFake;
    use AsObject;

    public function handle(SiteDomain $domain): void
    {
        $actor = auth()->user();
        if (! $actor instanceof Authenticatable) {
            return;
        }

        // Accessors resolve wildcard NULLs for display; claims need current storage values.
        $attributes = $domain->getAttributes();
        $conflict = FindInaccessibleSiteDomainClaimAction::run($actor, [
            'scheme' => $attributes['scheme'] ?? null,
            'host' => $attributes['domain'] ?? null,
            'port' => $attributes['port'] ?? null,
        ], $domain);

        if ($conflict instanceof SiteDomain) {
            throw ValidationException::withMessages([
                'domain' => __('capell-core::message.site_domain_claim_conflict'),
            ]);
        }
    }
}
