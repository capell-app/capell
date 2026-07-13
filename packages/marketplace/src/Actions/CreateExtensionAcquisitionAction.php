<?php

declare(strict_types=1);

namespace Capell\Marketplace\Actions;

use Capell\Marketplace\Data\ExtensionAcquisitionData;
use Capell\Marketplace\Data\ExtensionListingData;
use Capell\Marketplace\Data\InstalledPackageData;
use Capell\Marketplace\Data\MarketplaceInstallEligibilityData;
use Capell\Marketplace\Enums\MarketplaceInstallState;
use Capell\Marketplace\Models\MarketplaceInstance;
use Capell\Marketplace\Services\MarketplaceClient;
use Capell\Marketplace\Support\MarketplaceInstanceResolver;
use Capell\Marketplace\Support\MarketplacePayloadSigner;
use Lorisleiva\Actions\Concerns\AsAction;
use UnexpectedValueException;

final class CreateExtensionAcquisitionAction
{
    use AsAction;

    public function __construct(
        private readonly MarketplaceClient $marketplace,
        private readonly MarketplaceInstanceResolver $instances,
        private readonly MarketplacePayloadSigner $signer,
    ) {}

    /**
     * @param  array<string, mixed>  $installOptions
     */
    public function handle(
        ExtensionListingData $listing,
        ?string $licenseKey = null,
        ?string $email = null,
        ?string $domain = null,
        array $installOptions = [],
    ): ExtensionAcquisitionData {
        $resolvedEmail = $email ?? auth()->user()?->email ?? 'unknown@local';
        unset($domain);

        $selectedInstallOptions = $this->selectedInstallOptions($listing, $installOptions);

        if (! $this->requiresMarketplaceAuthorization($listing)) {
            $versionConstraint = $listing->latestVersion !== null ? '^' . $listing->latestVersion : '*';

            return new ExtensionAcquisitionData(
                composerName: $listing->composerName,
                versionConstraint: $versionConstraint,
                composerCommand: sprintf('composer require %s:%s', $listing->composerName, $versionConstraint),
                repositoryUrl: null,
                purchaseUrl: $listing->purchaseUrl,
                requiresDeployment: false,
                composerAuth: null,
                signedActivation: [],
                metadata: [
                    'authorization_source' => 'local_free_policy',
                ],
                authorizationEligibilityPolicy: new MarketplaceInstallEligibilityData(
                    state: MarketplaceInstallState::FreeAvailable,
                    canInstall: true,
                    canUpdate: true,
                    canRunExisting: true,
                    metadata: [
                        'source' => 'local_free_policy',
                        'can_install' => true,
                    ],
                ),
            );
        }

        AssertMarketplaceInstallAllowedAction::run(
            listing: $listing,
            instance: $this->marketplaceInstance(),
            action: 'install',
            remoteEligibility: $listing->installEligibilityPolicy,
        );

        $authorization = $this->marketplace->createInstallAuthorization(
            slug: $listing->slug,
            licenseKey: $licenseKey,
            email: $resolvedEmail,
            installOptions: $selectedInstallOptions,
        );

        throw_if($authorization->composerName !== '' && $authorization->composerName !== $listing->composerName, UnexpectedValueException::class, 'Marketplace authorization returned a package that does not match the selected extension.');

        $composerName = $authorization->composerName !== '' ? $authorization->composerName : $listing->composerName;
        $versionConstraint = $authorization->versionConstraint !== '' ? $authorization->versionConstraint : ($listing->latestVersion !== null ? '^' . $listing->latestVersion : '*');
        $repositoryUrl = $authorization->repositoryUrl;

        $acquisition = new ExtensionAcquisitionData(
            composerName: $composerName,
            versionConstraint: $versionConstraint,
            composerCommand: sprintf('composer require %s:%s', $composerName, $versionConstraint),
            repositoryUrl: $repositoryUrl,
            purchaseUrl: $listing->purchaseUrl,
            requiresDeployment: $repositoryUrl !== null,
            composerAuth: $authorization->composerAuth,
            signedActivation: $authorization->signedActivation,
            metadata: $authorization->metadata,
            authorizationEligibilityPolicy: $authorization->installEligibilityPolicy,
        );

        if ($this->authorizationBlocksInstall($authorization->installEligibilityPolicy)) {
            return $acquisition;
        }

        $this->recordInstallIntent(
            listing: $listing,
            composerName: $composerName,
            versionConstraint: $versionConstraint,
            selectedInstallOptions: $selectedInstallOptions,
        );

        return $acquisition;
    }

    private function authorizationBlocksInstall(?MarketplaceInstallEligibilityData $eligibility): bool
    {
        if (! $eligibility instanceof MarketplaceInstallEligibilityData) {
            return false;
        }

        if ($eligibility->blocksInstall()) {
            return true;
        }

        if ($eligibility->state === MarketplaceInstallState::PurchaseRequired) {
            return true;
        }

        return $eligibility->state === MarketplaceInstallState::ActivationRequired;
    }

    /**
     * @param  array<string, mixed>  $installOptions
     * @return array<string, mixed>
     */
    private function selectedInstallOptions(ExtensionListingData $listing, array $installOptions): array
    {
        $allowedKeys = [];

        foreach ($listing->installOptions as $option) {
            $key = $option['key'] ?? null;

            if (is_string($key) && $key !== '') {
                $allowedKeys[$key] = true;
            }
        }

        return $allowedKeys === [] ? [] : array_intersect_key($installOptions, $allowedKeys);
    }

    /**
     * @param  array<string, mixed>  $selectedInstallOptions
     */
    private function recordInstallIntent(
        ExtensionListingData $listing,
        string $composerName,
        string $versionConstraint,
        array $selectedInstallOptions,
    ): void {
        $marketplaceInstance = $this->marketplaceInstance();
        $instanceId = $marketplaceInstance?->instance_id ?? config('capell-marketplace.instance.id');

        if (! is_string($instanceId) || $instanceId === '') {
            return;
        }

        $payload = [
            'event_type' => 'install_intent',
            'source' => 'marketplace',
            'instance_id' => $instanceId,
            ...BuildMarketplaceConnectionContextAction::run($marketplaceInstance, $instanceId),
            'slug' => $listing->slug,
            'composer_name' => $composerName,
            'version_constraint' => $versionConstraint,
            'app_url' => config('app.url'),
            'install_options' => $selectedInstallOptions,
            'installed' => array_map(
                fn (InstalledPackageData $package): array => $package->toArray(),
                BuildInstalledPackageSnapshotAction::run(),
            ),
        ];

        $signingSecret = $marketplaceInstance?->signing_secret_encrypted ?? config('capell-marketplace.marketplace.webhook_secret');
        if (is_string($signingSecret) && $signingSecret !== '') {
            $payload = $this->signer->signedPayload($payload, $signingSecret);
        }

        $this->marketplace->recordInstallIntent($payload);
    }

    private function marketplaceInstance(): ?MarketplaceInstance
    {
        return $this->instances->latest();
    }

    private function requiresMarketplaceAuthorization(ExtensionListingData $listing): bool
    {
        if ($listing->isPaid || $listing->activationRequired) {
            return true;
        }

        $eligibility = $listing->installEligibilityPolicy;

        return $eligibility instanceof MarketplaceInstallEligibilityData
            && (
                $eligibility->blocksInstall()
                || $eligibility->state === MarketplaceInstallState::PurchaseRequired
                || $eligibility->state === MarketplaceInstallState::ActivationRequired
            );
    }
}
