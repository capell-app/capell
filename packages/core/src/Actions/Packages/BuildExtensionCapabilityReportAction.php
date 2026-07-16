<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Packages;

use Capell\Core\Data\ExtensionCapabilityReportData;
use Capell\Core\Data\ExtensionInstallImpactData;
use Capell\Core\Support\Manifest\CapellManifestData;
use Capell\Core\Support\PackageRegistry\CapellPackageRegistry;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * @method static ExtensionCapabilityReportData run(array<string, CapellManifestData>|null $manifests = null)
 */
final class BuildExtensionCapabilityReportAction
{
    use AsFake;
    use AsObject;

    /**
     * @param  array<string, CapellManifestData>|null  $manifests
     */
    public function handle(?array $manifests = null): ExtensionCapabilityReportData
    {
        $manifests ??= resolve(CapellPackageRegistry::class)->all();

        $graph = BuildPackageCapabilityGraphAction::run($manifests);
        $packages = [];

        foreach ($manifests as $manifest) {
            $packages[] = BuildExtensionInstallImpactAction::run($manifest, $graph);
        }

        return new ExtensionCapabilityReportData(
            packages: $packages,
            surfaces: $this->surfaces($packages),
            warnings: $this->warnings($packages),
        );
    }

    /**
     * @param  list<ExtensionInstallImpactData>  $packages
     * @return list<string>
     */
    private function surfaces(array $packages): array
    {
        return array_values(collect($packages)
            ->flatMap(fn (ExtensionInstallImpactData $package): array => $package->surfaces)
            ->unique()
            ->values()
            ->all());
    }

    /**
     * @param  list<ExtensionInstallImpactData>  $packages
     * @return list<string>
     */
    private function warnings(array $packages): array
    {
        return array_values(collect($packages)
            ->flatMap(fn (ExtensionInstallImpactData $package): array => $package->warnings)
            ->unique()
            ->values()
            ->all());
    }
}
