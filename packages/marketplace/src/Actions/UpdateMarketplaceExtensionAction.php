<?php

declare(strict_types=1);

namespace Capell\Marketplace\Actions;

use Capell\Marketplace\Contracts\MarketplaceInstalledPackageVersionResolver;
use Capell\Marketplace\Data\ExtensionAcquisitionData;
use Capell\Marketplace\Data\ExtensionListingData;
use Capell\Marketplace\Data\MarketplaceInstallActorData;
use Capell\Marketplace\Enums\MarketplaceInstallSource;
use Capell\Marketplace\Models\MarketplaceInstallAttempt;
use Capell\Marketplace\Services\MarketplaceClient;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * The whole one-click update, from a composer name to a queued operation.
 *
 * Everything an operator's single click has to establish lives here: that the
 * package is actually installed, that a newer version actually exists, that the
 * marketplace will authorize this site to take it, and only then that a queued
 * attempt exists. Each of those is a separate refusal with its own message,
 * because "update failed" is the least useful thing this could say.
 */
final class UpdateMarketplaceExtensionAction
{
    use AsFake;
    use AsObject;

    public function __construct(
        private readonly MarketplaceClient $marketplace,
        private readonly MarketplaceInstalledPackageVersionResolver $installedVersions,
    ) {}

    public function handle(
        string $composerName,
        MarketplaceInstallActorData $actor,
        MarketplaceInstallSource $source = MarketplaceInstallSource::TableHelper,
        ?string $idempotencyKey = null,
        bool $dispatch = true,
        bool $awaitPublishedDeployment = true,
    ): MarketplaceInstallAttempt {
        ['listing' => $listing, 'acquisition' => $acquisition, 'currentVersion' => $currentVersion] = $this->authorise(
            $composerName,
            $source->isUnattended(),
        );

        return QueueMarketplaceUpdateAttemptAction::run(
            listing: $listing,
            acquisition: $acquisition,
            actor: $actor,
            source: $source,
            currentVersion: $currentVersion,
            user: auth()->user(),
            idempotencyKey: $idempotencyKey,
            dispatch: $dispatch,
            awaitPublishedDeployment: $awaitPublishedDeployment,
        );
    }

    /**
     * Establish what this site may install: that the package is installed, that
     * a newer version exists and that the marketplace authorises it. Retrying a
     * failed update asks again through here rather than trusting the answer
     * the first attempt stored.
     *
     * @param  bool  $unattended  No operator is present, so the acquisition is pinned to one exact version.
     * @return array{listing: ExtensionListingData, acquisition: ExtensionAcquisitionData, currentVersion: string}
     */
    public function authorise(string $composerName, bool $unattended = false): array
    {
        $currentVersion = $this->installedVersions->prettyVersion($composerName);

        if ($currentVersion === null || $currentVersion === '') {
            throw ValidationException::withMessages([
                'composer_name' => __('capell-marketplace::marketplace.updates.not_installed', [
                    'package' => $composerName,
                ]),
            ]);
        }

        // Deliberately uncached. The catalogue record an operator was looking at
        // may be minutes old, and the version this authorizes is the version
        // Composer will be told to fetch.
        $listing = $this->marketplace->extensionsByComposerNames([$composerName], allowCache: false)[$composerName] ?? null;

        if (! $listing instanceof ExtensionListingData) {
            throw ValidationException::withMessages([
                'composer_name' => __('capell-marketplace::marketplace.updates.no_update_available', [
                    'package' => $composerName,
                ]),
            ]);
        }

        if (! $this->hasNewerVersion($currentVersion, $listing->latestVersion)) {
            throw ValidationException::withMessages([
                'composer_name' => __('capell-marketplace::marketplace.updates.no_update_available', [
                    'package' => $composerName,
                ]),
            ]);
        }

        $acquisition = CreateExtensionUpdateAcquisitionAction::run(
            listing: $listing,
            currentVersion: $currentVersion,
            unattended: $unattended,
        );

        return ['listing' => $listing, 'acquisition' => $acquisition, 'currentVersion' => $currentVersion];
    }

    private function hasNewerVersion(string $currentVersion, ?string $latestVersion): bool
    {
        if ($latestVersion === null || $latestVersion === '') {
            return false;
        }

        return version_compare(ltrim($latestVersion, 'v'), ltrim($currentVersion, 'v'), '>');
    }
}
