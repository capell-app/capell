<?php

declare(strict_types=1);

namespace Capell\Marketplace\Actions;

use Capell\Marketplace\Data\ExtensionListingData;
use Capell\Marketplace\Data\MarketplaceInstallEligibilityData;
use Capell\Marketplace\Enums\MarketplaceConnectionMode;
use Capell\Marketplace\Enums\MarketplaceInstallState;
use Capell\Marketplace\Models\MarketplaceInstance;
use Lorisleiva\Actions\Concerns\AsAction;

final class ResolveMarketplaceInstallEligibilityAction
{
    use AsAction;

    public function handle(
        ExtensionListingData $listing,
        ?MarketplaceInstance $instance,
        ?string $domain = null,
        string $action = 'install',
        ?MarketplaceInstallEligibilityData $remoteEligibility = null,
    ): MarketplaceInstallEligibilityData {
        unset($domain, $action);

        if (! $this->isProtectedInstall($listing, $remoteEligibility)) {
            return new MarketplaceInstallEligibilityData(
                state: MarketplaceInstallState::FreeAvailable,
                canInstall: true,
                canUpdate: true,
                canRunExisting: true,
                metadata: [
                    'source' => 'local_free_policy',
                    'can_install' => true,
                ],
            );
        }

        if (! $this->hasConnectedAccount($instance)) {
            return $this->blocked('account_required');
        }

        if (! $instance?->account_email_verified_at) {
            return $this->blocked('email_verification_required');
        }

        if (! $remoteEligibility instanceof MarketplaceInstallEligibilityData
            || (! $remoteEligibility->state instanceof MarketplaceInstallState && $remoteEligibility->metadata === [])) {
            return $this->blocked('entitlement_required', missingPolicy: true);
        }

        if ($remoteEligibility->blocksInstall()) {
            return $remoteEligibility->blockReason === 'missing_policy'
                ? $this->blocked('entitlement_required', missingPolicy: true)
                : $remoteEligibility;
        }

        if ($remoteEligibility->state === MarketplaceInstallState::Authorized || $remoteEligibility->canInstall) {
            return $remoteEligibility;
        }

        return match ($remoteEligibility->state) {
            MarketplaceInstallState::PurchaseRequired,
            MarketplaceInstallState::ActivationRequired => $remoteEligibility,
            default => $this->blocked('entitlement_required'),
        };
    }

    private function isProtectedInstall(ExtensionListingData $listing, ?MarketplaceInstallEligibilityData $remoteEligibility): bool
    {
        if ($listing->isPaid || $listing->activationRequired) {
            return true;
        }

        return $remoteEligibility instanceof MarketplaceInstallEligibilityData
            && (
                $remoteEligibility->blocksInstall()
                || $remoteEligibility->state === MarketplaceInstallState::PurchaseRequired
                || $remoteEligibility->state === MarketplaceInstallState::ActivationRequired
            );
    }

    private function hasConnectedAccount(?MarketplaceInstance $instance): bool
    {
        return $instance instanceof MarketplaceInstance
            && $instance->connection_mode === MarketplaceConnectionMode::AccountLinked
            && is_string($instance->account_id)
            && $instance->account_id !== '';
    }

    private function blocked(string $reason, bool $missingPolicy = false): MarketplaceInstallEligibilityData
    {
        return new MarketplaceInstallEligibilityData(
            state: MarketplaceInstallState::Blocked,
            blockReason: $reason,
            missingPolicy: $missingPolicy,
            metadata: [
                'reason' => $reason,
                'can_install' => false,
            ],
        );
    }
}
