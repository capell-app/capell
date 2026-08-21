<?php

declare(strict_types=1);

namespace Capell\Marketplace\Actions;

use Capell\Core\Data\PackageData;
use Capell\Core\Facades\CapellCore;
use Capell\Marketplace\Data\MarketplaceCatalogueLocalStateSnapshotData;
use Capell\Marketplace\Data\MarketplaceCatalogueOperationData;
use Capell\Marketplace\Enums\MarketplaceInstallIntentStatus;
use Capell\Marketplace\Models\MarketplaceInstallAttempt;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class BuildMarketplaceCatalogueLocalStateSnapshotAction
{
    use AsFake;
    use AsObject;

    public function handle(): MarketplaceCatalogueLocalStateSnapshotData
    {
        $downloadedPackages = CapellCore::getPackages()
            ->filter(fn (PackageData $package): bool => CapellCore::isPackageInstalled($package->name)
                || CapellCore::isPackageAvailable($package->name));
        $installedVersions = [];

        foreach ($downloadedPackages as $package) {
            if (CapellCore::isPackageInstalled($package->name)) {
                $installedVersions[$package->name] = $package->version;
            }
        }

        $activeOperations = MarketplaceInstallAttempt::query()
            ->whereIn('status', [
                MarketplaceInstallIntentStatus::Queued->value,
                MarketplaceInstallIntentStatus::Running->value,
                MarketplaceInstallIntentStatus::CancelRequested->value,
            ])
            ->latest('updated_at')
            ->get()
            ->unique('composer_name')
            ->mapWithKeys(static fn (MarketplaceInstallAttempt $attempt): array => [
                $attempt->composer_name => new MarketplaceCatalogueOperationData(
                    id: (int) $attempt->getKey(),
                    status: $attempt->status,
                ),
            ])
            ->all();

        return new MarketplaceCatalogueLocalStateSnapshotData(
            downloadedComposerNames: array_values($downloadedPackages
                ->pluck('name')
                ->merge(array_keys($activeOperations))
                ->unique()
                ->values()
                ->all()),
            installedVersions: $installedVersions,
            activeOperations: $activeOperations,
        );
    }
}
