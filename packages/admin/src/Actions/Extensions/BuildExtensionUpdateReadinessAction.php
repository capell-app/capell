<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Extensions;

use Capell\Admin\Contracts\Extensions\ExtensionUpdateMetadataProvider;
use Capell\Admin\Data\Extensions\ExtensionOperationPackageData;
use Capell\Admin\Data\Extensions\ExtensionUpdateReadinessData;
use Lorisleiva\Actions\Concerns\AsAction;

final class BuildExtensionUpdateReadinessAction
{
    use AsAction;

    /** @return list<ExtensionUpdateReadinessData> */
    public function handle(): array
    {
        return array_values(collect(array_values(BuildExtensionOperationsSummaryAction::run()->packages))
            ->map(fn (ExtensionOperationPackageData $package): ExtensionUpdateReadinessData => $this->readiness($package))
            ->values()
            ->all());
    }

    private function readiness(ExtensionOperationPackageData $package): ExtensionUpdateReadinessData
    {
        foreach (app()->tagged(ExtensionUpdateMetadataProvider::TAG) as $provider) {
            $readiness = $provider->updateReadiness($package);

            if ($readiness instanceof ExtensionUpdateReadinessData) {
                return $readiness;
            }
        }

        if ($package->blocked) {
            return new ExtensionUpdateReadinessData($package->packageName, 'blocked', $package->version, $package->latestVersion, $package->runtimeStatus);
        }

        if ($package->latestVersion === null) {
            return new ExtensionUpdateReadinessData($package->packageName, 'unknown', $package->version);
        }

        if (! $package->updateAvailable || $package->version === null) {
            return new ExtensionUpdateReadinessData($package->packageName, 'none', $package->version, $package->latestVersion);
        }

        $current = explode('.', ltrim($package->version, 'v'));
        $latest = explode('.', ltrim($package->latestVersion, 'v'));

        $state = match (true) {
            ($latest[0] ?? null) !== ($current[0] ?? null) => 'major_review',
            ($latest[1] ?? null) !== ($current[1] ?? null) => 'minor_ready',
            default => 'patch_ready',
        };

        return new ExtensionUpdateReadinessData($package->packageName, $state, $package->version, $package->latestVersion);
    }
}
