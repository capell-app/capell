<?php

declare(strict_types=1);

namespace Capell\Marketplace\Support;

use Capell\Marketplace\Data\ExtensionListingData;
use Capell\Marketplace\Data\MarketplaceInstallEligibilityData;
use Capell\Marketplace\Enums\MarketplaceInstallState;

final class MarketplaceInstallAuthorizationPolicy
{
    public function requiresAuthorization(ExtensionListingData $listing): bool
    {
        return $listing->isPaid
            || $listing->activationRequired
            || $this->blocksInstall($listing->installEligibilityPolicy);
    }

    public function blocksInstall(?MarketplaceInstallEligibilityData $eligibility): bool
    {
        return $eligibility instanceof MarketplaceInstallEligibilityData
            && (
                $eligibility->blocksInstall()
                || $eligibility->state === MarketplaceInstallState::PurchaseRequired
                || $eligibility->state === MarketplaceInstallState::ActivationRequired
            );
    }
}
