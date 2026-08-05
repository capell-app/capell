<?php

declare(strict_types=1);

namespace Capell\Marketplace\Actions;

use Capell\Marketplace\Data\ExtensionAcquisitionData;
use Capell\Marketplace\Data\ExtensionListingData;
use Capell\Marketplace\Data\MarketplaceInstallEligibilityData;
use Capell\Marketplace\Enums\MarketplaceInstallState;
use Capell\Marketplace\Services\MarketplaceClient;
use Capell\Marketplace\Support\MarketplaceInstallAuthorizationPolicy;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use UnexpectedValueException;

/**
 * Turn "there is a newer version of this extension" into something Composer can
 * actually be told to do.
 *
 * MarketplaceClient::createUpgradeAuthorization() has existed since the update
 * detection was written and has never had a caller: the product could see an
 * update and had no way to take it. This is that caller, and it deliberately
 * produces the same ExtensionAcquisitionData an install produces, so the queued
 * operation downstream does not need to know which of the two put it there.
 */
final class CreateExtensionUpdateAcquisitionAction
{
    use AsFake;
    use AsObject;

    public function __construct(
        private readonly MarketplaceClient $marketplace,
        private readonly MarketplaceInstallAuthorizationPolicy $authorizationPolicy,
    ) {}

    public function handle(ExtensionListingData $listing, string $currentVersion): ExtensionAcquisitionData
    {
        $this->assertUpdateAllowed($listing);

        if (! $this->authorizationPolicy->requiresAuthorization($listing)) {
            $versionConstraint = $this->latestVersionConstraint($listing);

            return new ExtensionAcquisitionData(
                composerName: $listing->composerName,
                versionConstraint: $versionConstraint,
                composerCommand: sprintf('composer require %s:%s', $listing->composerName, $versionConstraint),
                repositoryUrl: null,
                purchaseUrl: $listing->purchaseUrl,
                requiresDeployment: false,
                signedActivation: [],
                metadata: [
                    'authorization_source' => 'local_free_policy',
                ],
                authorizationEligibilityPolicy: new MarketplaceInstallEligibilityData(
                    state: MarketplaceInstallState::UpdateAvailable,
                    canInstall: true,
                    canUpdate: true,
                    canRunExisting: true,
                    metadata: [
                        'source' => 'local_free_policy',
                        'can_update' => true,
                    ],
                ),
            );
        }

        $authorization = $this->marketplace->createUpgradeAuthorization(
            composerName: $listing->composerName,
            currentVersion: $currentVersion,
        );

        throw_if(
            $authorization->composerName !== '' && $authorization->composerName !== $listing->composerName,
            UnexpectedValueException::class,
            'Marketplace update authorization returned a package that does not match the installed extension.',
        );

        $composerName = $authorization->composerName !== '' ? $authorization->composerName : $listing->composerName;
        $versionConstraint = $authorization->versionConstraint !== ''
            ? $authorization->versionConstraint
            : $this->latestVersionConstraint($listing);

        return new ExtensionAcquisitionData(
            composerName: $composerName,
            versionConstraint: $versionConstraint,
            composerCommand: sprintf('composer require %s:%s', $composerName, $versionConstraint),
            repositoryUrl: $authorization->repositoryUrl,
            purchaseUrl: $listing->purchaseUrl,
            requiresDeployment: $authorization->repositoryUrl !== null,
            composerAuth: $authorization->composerAuth,
            signedActivation: $authorization->signedActivation,
            metadata: $authorization->metadata,
            authorizationEligibilityPolicy: $listing->installEligibilityPolicy,
        );
    }

    /**
     * MarketplaceInstallEligibilityData::$canUpdate is the marketplace's own
     * answer to "may this site take a newer version of this extension", and
     * until now nothing read it. It is the gate rather than a second notion of
     * update entitlement invented here, so a licence that has lapsed since the
     * install stops updates without stopping the extension that is already
     * running — which is what canRunExisting is separately for.
     */
    private function assertUpdateAllowed(ExtensionListingData $listing): void
    {
        $eligibility = $listing->installEligibilityPolicy;

        if (! $eligibility instanceof MarketplaceInstallEligibilityData) {
            return;
        }

        if ($this->authorizationPolicy->blocksInstall($eligibility)) {
            throw ValidationException::withMessages([
                'composer_name' => __('capell-marketplace::marketplace.updates.blocked', [
                    'package' => $listing->composerName,
                    'reason' => $eligibility->blockReason ?? 'blocked',
                ]),
            ]);
        }

        if (! $eligibility->canUpdate) {
            throw ValidationException::withMessages([
                'composer_name' => __('capell-marketplace::marketplace.updates.not_entitled', [
                    'package' => $listing->composerName,
                ]),
            ]);
        }
    }

    private function latestVersionConstraint(ExtensionListingData $listing): string
    {
        return $listing->latestVersion !== null ? '^' . $listing->latestVersion : '*';
    }
}
