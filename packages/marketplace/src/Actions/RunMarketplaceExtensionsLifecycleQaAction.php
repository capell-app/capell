<?php

declare(strict_types=1);

namespace Capell\Marketplace\Actions;

use Capell\Core\Actions\UninstallPackageAction;
use Capell\Core\Facades\CapellCore;
use Capell\Marketplace\Contracts\MarketplaceComposerRunner;
use Capell\Marketplace\Data\ExtensionListingData;
use Capell\Marketplace\Data\MarketplaceExtensionLifecycleQaResultData;
use Capell\Marketplace\Enums\MarketplaceInstallIntentStatus;
use Capell\Marketplace\Jobs\RunMarketplaceInstallAttemptJob;
use Capell\Marketplace\Services\MarketplaceClient;
use Capell\Marketplace\Support\MarketplaceInstanceResolver;
use Illuminate\Support\Facades\Log;
use Lorisleiva\Actions\Concerns\AsAction;
use Throwable;

final class RunMarketplaceExtensionsLifecycleQaAction
{
    use AsAction;

    public function __construct(
        private readonly MarketplaceClient $marketplace,
        private readonly MarketplaceInstanceResolver $instances,
        private readonly MarketplaceComposerRunner $composer,
    ) {}

    /**
     * @return array<int, MarketplaceExtensionLifecycleQaResultData>
     */
    public function handle(
        ?string $only = null,
        bool $skipDelete = false,
        bool $stopOnFailure = false,
        bool $dryRun = false,
    ): array {
        $results = [];

        foreach ($this->installableListings($only) as $listing) {
            $result = $this->runListing($listing, $skipDelete, $dryRun);
            $results[] = $result;

            if ($stopOnFailure && $result->failed()) {
                break;
            }
        }

        return $results;
    }

    /**
     * @return array<int, ExtensionListingData>
     */
    private function installableListings(?string $only): array
    {
        return collect($this->marketplace->listExtensions())
            ->filter(fn (ExtensionListingData $listing): bool => $listing->composerName !== '')
            ->unique(fn (ExtensionListingData $listing): string => $listing->composerName)
            ->when(
                $only !== null && trim($only) !== '',
                fn ($listings) => $listings->filter(
                    fn (ExtensionListingData $listing): bool => $listing->composerName === trim((string) $only),
                ),
            )
            ->values()
            ->all();
    }

    private function runListing(ExtensionListingData $listing, bool $skipDelete, bool $dryRun): MarketplaceExtensionLifecycleQaResultData
    {
        if ($dryRun) {
            return new MarketplaceExtensionLifecycleQaResultData(
                name: $listing->name,
                composerName: $listing->composerName,
                installResult: 'dry-run',
                uninstallResult: 'dry-run',
                deleteResult: $skipDelete ? 'skipped' : 'dry-run',
            );
        }

        Log::info('Marketplace lifecycle QA installing extension.', [
            'composer_name' => $listing->composerName,
            'extension_slug' => $listing->slug,
        ]);

        try {
            $acquisition = CreateExtensionAcquisitionAction::run($listing);
            $eligibility = ResolveMarketplaceInstallEligibilityAction::run(
                listing: $listing,
                instance: $this->instances->latest(),
                remoteEligibility: $acquisition->authorizationEligibilityPolicy,
            );

            $attempt = QueueMarketplaceInstallAttemptAction::run(
                listing: $listing,
                acquisition: $acquisition,
                eligibility: $eligibility,
                context: [
                    'source' => 'marketplace_lifecycle_qa',
                ],
            );

            new RunMarketplaceInstallAttemptJob((int) $attempt->getKey())->handle($this->composer);
            $attempt->refresh();

            if ($attempt->status !== MarketplaceInstallIntentStatus::Succeeded) {
                return $this->failedResult($listing, 'failed', 'skipped', $skipDelete ? 'skipped' : 'skipped', $attempt->failure_reason);
            }

            return $this->uninstallListing($listing, $skipDelete);
        } catch (Throwable $throwable) {
            report($throwable);

            return $this->failedResult($listing, 'failed', 'skipped', $skipDelete ? 'skipped' : 'skipped', $throwable->getMessage());
        }
    }

    private function uninstallListing(ExtensionListingData $listing, bool $skipDelete): MarketplaceExtensionLifecycleQaResultData
    {
        Log::info('Marketplace lifecycle QA uninstalling extension.', [
            'composer_name' => $listing->composerName,
            'delete_data' => ! $skipDelete,
        ]);

        try {
            if (! CapellCore::hasPackage($listing->composerName)) {
                return $this->failedResult($listing, 'passed', 'failed', $skipDelete ? 'skipped' : 'skipped', 'Installed package was not discovered by Capell.');
            }

            UninstallPackageAction::run(
                package: CapellCore::getPackage($listing->composerName),
                delete: false,
                deleteData: ! $skipDelete,
            );

            return new MarketplaceExtensionLifecycleQaResultData(
                name: $listing->name,
                composerName: $listing->composerName,
                installResult: 'passed',
                uninstallResult: 'passed',
                deleteResult: $skipDelete ? 'skipped' : 'passed',
            );
        } catch (Throwable $throwable) {
            report($throwable);

            return $this->failedResult($listing, 'passed', 'failed', $skipDelete ? 'skipped' : 'failed', $throwable->getMessage());
        }
    }

    private function failedResult(
        ExtensionListingData $listing,
        string $installResult,
        string $uninstallResult,
        string $deleteResult,
        ?string $failureReason,
    ): MarketplaceExtensionLifecycleQaResultData {
        return new MarketplaceExtensionLifecycleQaResultData(
            name: $listing->name,
            composerName: $listing->composerName,
            installResult: $installResult,
            uninstallResult: $uninstallResult,
            deleteResult: $deleteResult,
            failureReason: $failureReason,
        );
    }
}
