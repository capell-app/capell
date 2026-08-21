<?php

declare(strict_types=1);

namespace Capell\Marketplace\Actions;

use Capell\Marketplace\Data\ExtensionListingData;
use Capell\Marketplace\Data\MarketplaceCatalogueLocalStateData;
use Capell\Marketplace\Data\MarketplaceCatalogueLocalStateSnapshotData;
use Capell\Marketplace\Data\MarketplaceCatalogueOperationData;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class ResolveMarketplaceCatalogueLocalStateAction
{
    use AsFake;
    use AsObject;

    public function handle(
        ExtensionListingData $listing,
        MarketplaceCatalogueLocalStateSnapshotData $snapshot,
        bool $includeLocalState = true,
    ): MarketplaceCatalogueLocalStateData {
        if (! $includeLocalState) {
            return MarketplaceCatalogueLocalStateData::withoutLocalState();
        }

        $installed = false;
        $installedVersion = null;
        $activeOperation = null;

        foreach (ExtensionListingData::localPackageComposerNameCandidates($listing->composerName) as $composerName) {
            if (! $installed && array_key_exists($composerName, $snapshot->installedVersions)) {
                $installed = true;
                $installedVersion = $snapshot->installedVersions[$composerName];
            }

            if (! $activeOperation instanceof MarketplaceCatalogueOperationData
                && array_key_exists($composerName, $snapshot->activeOperations)) {
                $activeOperation = $snapshot->activeOperations[$composerName];
            }
        }

        return new MarketplaceCatalogueLocalStateData(
            isInstalled: $installed,
            installedVersion: $installedVersion,
            hasUpdateAvailable: $installed && $this->hasUpdateAvailable($installedVersion, $listing->latestVersion),
            activeOperationId: $activeOperation?->id,
            activeOperationStatus: $activeOperation?->status,
        );
    }

    private function hasUpdateAvailable(?string $installedVersion, ?string $latestVersion): bool
    {
        if (! $this->isComparableVersion($installedVersion) || ! $this->isComparableVersion($latestVersion)) {
            return false;
        }

        return version_compare(ltrim((string) $installedVersion, 'v'), ltrim((string) $latestVersion, 'v'), '<');
    }

    private function isComparableVersion(?string $version): bool
    {
        return is_string($version)
            && preg_match('/^v?\d+(?:\.\d+){0,3}(?:[-+][0-9A-Za-z.-]+)?$/', $version) === 1;
    }
}
