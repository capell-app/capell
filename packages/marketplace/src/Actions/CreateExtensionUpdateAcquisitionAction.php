<?php

declare(strict_types=1);

namespace Capell\Marketplace\Actions;

use Capell\Marketplace\Data\ExtensionAcquisitionData;
use Capell\Marketplace\Data\ExtensionListingData;
use Capell\Marketplace\Data\MarketplaceInstallEligibilityData;
use Capell\Marketplace\Enums\MarketplaceInstallState;
use Capell\Marketplace\Services\MarketplaceClient;
use Capell\Marketplace\Support\MarketplaceComposerCommand;
use Capell\Marketplace\Support\MarketplaceComposerCoordinates;
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

    /**
     * @param  bool  $unattended  Nobody is watching this update run (the auto-updater). The constraint is
     *                            then the exact version the listing advertises, and any other constraint
     *                            is refused here, before anything is queued for Composer. An operator who
     *                            asks for an update themselves keeps the bounded `^` range.
     */
    public function handle(ExtensionListingData $listing, string $currentVersion, bool $unattended = false): ExtensionAcquisitionData
    {
        $this->assertUpdateAllowed($listing);
        MarketplaceComposerCoordinates::assertValid($listing->composerName, '*');

        if (! $this->authorizationPolicy->requiresAuthorization($listing)) {
            $versionConstraint = $this->latestVersionConstraint($listing, $unattended);
            $this->assertConstraint($versionConstraint, $listing, $unattended);
            MarketplaceComposerCoordinates::assertUpgrade($listing->composerName, $versionConstraint, $currentVersion);

            return new ExtensionAcquisitionData(
                composerName: $listing->composerName,
                versionConstraint: $versionConstraint,
                composerCommand: MarketplaceComposerCommand::require($listing->composerName, $versionConstraint),
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
                releaseChecksum: $listing->releaseChecksum,
                releaseChecksumVersion: $listing->releaseChecksumVersion ?? $listing->latestVersion,
                releaseContentSha256: $listing->releaseContentSha256,
                releaseDigests: $listing->releaseDigests,
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
            : $this->latestVersionConstraint($listing, $unattended);

        $this->assertConstraint($versionConstraint, $listing, $unattended);
        MarketplaceComposerCoordinates::assertUpgrade($composerName, $versionConstraint, $currentVersion);

        return new ExtensionAcquisitionData(
            composerName: $composerName,
            versionConstraint: $versionConstraint,
            composerCommand: MarketplaceComposerCommand::require($composerName, $versionConstraint),
            repositoryUrl: $authorization->repositoryUrl,
            purchaseUrl: $listing->purchaseUrl,
            requiresDeployment: $authorization->repositoryUrl !== null,
            composerAuth: $authorization->composerAuth,
            signedActivation: $authorization->signedActivation,
            metadata: $authorization->metadata,
            authorizationEligibilityPolicy: $listing->installEligibilityPolicy,
            releaseChecksum: $listing->releaseChecksum,
            releaseChecksumVersion: $listing->releaseChecksumVersion ?? $listing->latestVersion,
            releaseContentSha256: $listing->releaseContentSha256,
            releaseDigests: $listing->releaseDigests,
            expiresAt: $authorization->expiresAt,
        );
    }

    private function assertConstraint(string $versionConstraint, ExtensionListingData $listing, bool $unattended): void
    {
        if ($unattended) {
            $this->assertUnattendedPin($versionConstraint, $listing);
        }

        AssertMarketplaceUpdateConstraintAction::assertStrictConstraint($versionConstraint);
    }

    /**
     * The pin must be the advertised latest version, and when the listing
     * advertises a release digest for a version, that same one. A digest for
     * one version beside a constraint that resolves another proves nothing.
     */
    private function assertUnattendedPin(string $versionConstraint, ExtensionListingData $listing): void
    {
        if ($listing->latestVersion === null || $listing->latestVersion === '') {
            throw ValidationException::withMessages([
                'version_constraint' => __('capell-marketplace::marketplace.errors.unattended_update_no_version'),
            ]);
        }

        AssertMarketplaceUpdateConstraintAction::assertExactVersion($versionConstraint, $listing->latestVersion);

        if ($listing->releaseChecksumVersion !== null && $listing->releaseChecksumVersion !== '') {
            AssertMarketplaceUpdateConstraintAction::assertExactVersion($versionConstraint, $listing->releaseChecksumVersion);
        }
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

    private function latestVersionConstraint(ExtensionListingData $listing, bool $unattended = false): string
    {
        if ($listing->latestVersion === null) {
            return '*';
        }

        return ($unattended ? '' : '^') . $listing->latestVersion;
    }
}
