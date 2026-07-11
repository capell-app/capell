<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Extensions;

use Capell\Admin\Contracts\Extensions\ExtensionDependencyProvider;
use Capell\Admin\Data\Extensions\ExtensionDependencyBlockData;
use Capell\Admin\Data\Extensions\ExtensionOperationPackageData;
use Capell\Core\Facades\CapellCore;
use Lorisleiva\Actions\Concerns\AsAction;

final class BuildExtensionDependencyGraphAction
{
    use AsAction;

    /** @return list<ExtensionDependencyBlockData> */
    public function handle(): array
    {
        return array_values(collect(array_values(BuildExtensionOperationsSummaryAction::run()->packages))
            ->flatMap(fn (ExtensionOperationPackageData $package): array => [
                ...$this->coreBlockers($package),
                ...$this->providerBlockers($package),
            ])
            ->values()
            ->all());
    }

    /** @return list<ExtensionDependencyBlockData> */
    private function coreBlockers(ExtensionOperationPackageData $package): array
    {
        if (! $package->installed || CapellCore::canUninstallPackage($package->packageName)) {
            return [];
        }

        return [new ExtensionDependencyBlockData(
            packageName: $package->packageName,
            blockedPackageName: $package->packageName,
            operation: 'uninstall',
            reason: $package->core ? 'protected_core_package' : 'dependency_blocked',
        )];
    }

    /** @return list<ExtensionDependencyBlockData> */
    private function providerBlockers(ExtensionOperationPackageData $package): array
    {
        return array_values(collect(app()->tagged(ExtensionDependencyProvider::TAG))
            ->flatMap(fn (ExtensionDependencyProvider $provider): array => $provider->blockers($package))
            ->values()
            ->all());
    }
}
