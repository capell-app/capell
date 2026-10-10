<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Packages;

use Capell\Core\Data\PackageData;
use Capell\Core\Data\PackageRequirementData;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Support\Packages\TrustedCorePackages;
use Lorisleiva\Actions\Concerns\AsObject;

final class FindUnmetPackageRequirementsAction
{
    use AsObject;

    /** @return list<PackageRequirementData> */
    public function handle(?PackageData $package = null): array
    {
        $packages = $package instanceof PackageData ? collect([$package]) : CapellCore::getPackages(withoutCore: false);
        $unmet = [];

        foreach ($packages as $package) {
            if (! CapellCore::isPackageEnabled($package->name)) {
                continue;
            }

            foreach ($package->getRequirements() as $requirement) {
                if ($this->requirementIsBlocked($requirement, [$package->name])) {
                    $unmet[] = new PackageRequirementData($package->name, $requirement);
                }
            }
        }

        return $unmet;
    }

    /** @param list<string> $ancestors */
    private function requirementIsBlocked(string $requirement, array $ancestors): bool
    {
        if (TrustedCorePackages::isCoreRuntimePackage($requirement)) {
            return false;
        }

        if (! CapellCore::isPackageEnabled($requirement)) {
            return true;
        }

        // Circular requirements have their own upgrade/runtime error. Avoid
        // turning a diagnostic scan into unbounded recursion.
        if (in_array($requirement, $ancestors, true)) {
            return false;
        }

        foreach (CapellCore::getPackage($requirement)->getRequirements() as $dependency) {
            if ($this->requirementIsBlocked($dependency, [...$ancestors, $requirement])) {
                return true;
            }
        }

        return false;
    }
}
