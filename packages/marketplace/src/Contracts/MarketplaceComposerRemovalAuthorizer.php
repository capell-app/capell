<?php

declare(strict_types=1);

namespace Capell\Marketplace\Contracts;

use Capell\Marketplace\Data\MarketplaceComposerRemovalRequestData;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Authorises repository removal at the authenticated enqueue boundary.
 *
 * Register independently of install publishing. Keep a configured but unusable
 * deployment source visible and decline approval so Marketplace can show the
 * manual step. Decline when the repository or branch cannot be selected exactly.
 *
 * Store approval against the actor, uninstall attempt, exact package names and
 * repository identity. The returned value is an opaque lookup identifier. At
 * remove(), verify and consume the persisted approval once, check its expiry,
 * and revalidate the actor's current publication permission and repository.
 *
 * @experimental
 */
interface MarketplaceComposerRemovalAuthorizer extends MarketplaceComposerRequirementRemover
{
    public const string TAG = 'capell-marketplace.composer-removal-authorizer';

    public function authorize(MarketplaceComposerRemovalRequestData $request, Authenticatable $actor): ?string;
}
