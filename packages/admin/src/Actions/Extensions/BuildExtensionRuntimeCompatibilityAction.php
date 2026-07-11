<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Extensions;

use Capell\Admin\Contracts\Extensions\ExtensionRuntimeCheckProvider;
use Capell\Admin\Data\Extensions\ExtensionOperationPackageData;
use Capell\Admin\Data\Extensions\ExtensionRuntimeCompatibilityData;
use Lorisleiva\Actions\Concerns\AsAction;

final class BuildExtensionRuntimeCompatibilityAction
{
    use AsAction;

    /** @return list<ExtensionRuntimeCompatibilityData> */
    public function handle(): array
    {
        return array_values(collect(array_values(BuildExtensionOperationsSummaryAction::run()->packages))
            ->flatMap(fn (ExtensionOperationPackageData $package): array => [
                $this->baseCompatibility($package),
                ...$this->providerChecks($package),
            ])
            ->values()
            ->all());
    }

    private function baseCompatibility(ExtensionOperationPackageData $package): ExtensionRuntimeCompatibilityData
    {
        return new ExtensionRuntimeCompatibilityData(
            packageName: $package->packageName,
            state: $package->runtimeAllowed && $package->missingRequiredTables === [] ? 'pass' : 'blocked',
            requirements: $package->missingRequiredTables,
            message: $package->runtimeAllowed ? null : $package->runtimeStatus,
        );
    }

    /** @return list<ExtensionRuntimeCompatibilityData> */
    private function providerChecks(ExtensionOperationPackageData $package): array
    {
        return array_values(collect(app()->tagged(ExtensionRuntimeCheckProvider::TAG))
            ->flatMap(fn (ExtensionRuntimeCheckProvider $provider): array => $provider->checks($package))
            ->values()
            ->all());
    }
}
