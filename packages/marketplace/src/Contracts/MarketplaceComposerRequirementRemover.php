<?php

declare(strict_types=1);

namespace Capell\Marketplace\Contracts;

use Capell\Marketplace\Data\MarketplaceComposerPublicationResultData;
use Capell\Marketplace\Data\MarketplaceComposerRemovalRequestData;

/**
 * Optional companion to MarketplaceComposerChangePublisher: a publisher that
 * also implements this can take a requirement back out of the deployment
 * source when an extension is uninstalled.
 *
 * Kept as its own interface rather than a new method on the publisher, so a
 * publisher built against an older Core keeps loading. Queued Marketplace
 * uninstalls also require a MarketplaceComposerRemovalAuthorizer.
 *
 * @experimental
 */
interface MarketplaceComposerRequirementRemover
{
    public function remove(MarketplaceComposerRemovalRequestData $request): MarketplaceComposerPublicationResultData;
}
