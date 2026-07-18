<?php

declare(strict_types=1);

namespace Capell\Core\Support\Install;

use Capell\Core\Data\PackageData;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Support\Packages\TrustedCorePackages;
use Exception;
use Illuminate\Support\Collection;

final class PackageWorkflowPlanner
{
    /** @var list<string> */
    private const array FINAL_PACKAGE_NAMES = [
        'capell-app/publishing-studio',
        'capell-app/worktree',
    ];

    /**
     * @param  Collection<string, PackageData>  $availablePackages
     * @param  array<int, string>  $selectedPackageNames
     * @return Collection<string, PackageData>
     */
    public function expandAndOrder(Collection $availablePackages, array $selectedPackageNames, bool $includeInstalledRequirements = false): Collection
    {
        $expandedPackageNames = $this->expandSelectedPackageNames($availablePackages, $selectedPackageNames, $includeInstalledRequirements);

        return $this->order(
            $availablePackages->filter(
                fn (PackageData $package): bool => in_array($package->name, $expandedPackageNames, true),
            ),
        );
    }

    /**
     * @param  Collection<string, PackageData>  $packages
     * @return Collection<string, PackageData>
     */
    public function order(Collection $packages): Collection
    {
        $orderedPackages = $packages
            ->sortBy(fn (PackageData $package): array => [
                $this->finalPackageRank($package->name),
                $package->getSort(),
                $package->name,
            ]);

        return $this->sortByDependencies($orderedPackages);
    }

    /**
     * @param  Collection<string, PackageData>  $availablePackages
     * @param  array<int, string>  $selectedPackageNames
     * @return array<int, string>
     */
    private function expandSelectedPackageNames(Collection $availablePackages, array $selectedPackageNames, bool $includeInstalledRequirements): array
    {
        $expandedPackageNames = [];

        foreach ($selectedPackageNames as $packageName) {
            if ($this->isComposerOnlyCorePackageName($packageName)) {
                continue;
            }

            if (! $availablePackages->has($packageName)) {
                continue;
            }

            $this->addPackageWithRequirements(
                $availablePackages,
                $packageName,
                $expandedPackageNames,
                $includeInstalledRequirements,
                $selectedPackageNames,
            );
        }

        return $expandedPackageNames;
    }

    /**
     * @param  Collection<string, PackageData>  $availablePackages
     * @param  array<int, string>  $expandedPackageNames
     * @param  array<int, string>  $selectedPackageNames
     * @param  array<int, string>  $resolvingPackageNames
     */
    private function addPackageWithRequirements(
        Collection $availablePackages,
        string $packageName,
        array &$expandedPackageNames,
        bool $includeInstalledRequirements,
        array $selectedPackageNames,
        array $resolvingPackageNames = [],
    ): void {
        if (in_array($packageName, $expandedPackageNames, true) || ! $availablePackages->has($packageName)) {
            return;
        }

        if (in_array($packageName, $resolvingPackageNames, true)) {
            return;
        }

        $resolvingPackageNames[] = $packageName;

        $package = $availablePackages->get($packageName);

        if (! $package instanceof PackageData) {
            return;
        }

        foreach ($package->getRequirements() as $requiredPackageName) {
            if ($this->shouldSkipRequirement($requiredPackageName, $availablePackages, $includeInstalledRequirements)) {
                continue;
            }

            $this->addPackageWithRequirements(
                $availablePackages,
                $requiredPackageName,
                $expandedPackageNames,
                $includeInstalledRequirements,
                $selectedPackageNames,
                $resolvingPackageNames,
            );
        }

        foreach ($package->getSupportingPackages() as $supportingPackageName) {
            if ($this->shouldSkipRequirement($supportingPackageName, $availablePackages, $includeInstalledRequirements)) {
                continue;
            }

            $supportingPackage = $availablePackages->get($supportingPackageName);

            if (! $supportingPackage instanceof PackageData) {
                continue;
            }

            if (! $this->supportingPackageRequirementsAreSatisfied(
                $supportingPackage,
                array_values(array_unique([...$selectedPackageNames, ...$expandedPackageNames, $packageName])),
            )) {
                continue;
            }

            $this->addPackageWithRequirements(
                $availablePackages,
                $supportingPackageName,
                $expandedPackageNames,
                $includeInstalledRequirements,
                $selectedPackageNames,
                $resolvingPackageNames,
            );
        }

        $expandedPackageNames[] = $packageName;
    }

    /**
     * @param  Collection<string, PackageData>  $availablePackages
     */
    private function shouldSkipRequirement(string $packageName, Collection $availablePackages, bool $includeInstalledRequirements): bool
    {
        if (TrustedCorePackages::contains($packageName)) {
            return true;
        }

        if (! $availablePackages->has($packageName)) {
            return true;
        }

        return ! $includeInstalledRequirements && CapellCore::isPackageInstalled($packageName);
    }

    /**
     * @param  array<int, string>  $availablePackageNames
     */
    private function supportingPackageRequirementsAreSatisfied(PackageData $supportingPackage, array $availablePackageNames): bool
    {
        foreach ($supportingPackage->getRequirements() as $requiredPackageName) {
            if ($this->isComposerOnlyCorePackageName($requiredPackageName)) {
                continue;
            }

            if (CapellCore::isPackageInstalled($requiredPackageName)) {
                continue;
            }

            if (in_array($requiredPackageName, $availablePackageNames, true)) {
                continue;
            }

            return false;
        }

        return true;
    }

    /**
     * @param  Collection<string, PackageData>  $packages
     * @return Collection<string, PackageData>
     */
    private function sortByDependencies(Collection $packages): Collection
    {
        $sortedPackages = collect();
        $processedPackageNames = [];
        $allPackageNames = $packages->keys()->all();
        $maxIterations = $packages->count() + 1;
        $iteration = 0;

        while ($sortedPackages->count() < $packages->count() && $iteration < $maxIterations) {
            $iteration++;
            $addedPackage = false;

            foreach ($packages as $package) {
                if (in_array($package->name, $processedPackageNames, true)) {
                    continue;
                }

                $unmetRequirements = array_filter(
                    $package->getRequirements(),
                    fn (string $requiredPackageName): bool => in_array($requiredPackageName, $allPackageNames, true)
                        && ! in_array($requiredPackageName, $processedPackageNames, true),
                );

                if ($unmetRequirements === []) {
                    $sortedPackages->put($package->name, $package);
                    $processedPackageNames[] = $package->name;
                    $addedPackage = true;
                }
            }

            if (! $addedPackage && $sortedPackages->count() < $packages->count()) {
                $unprocessedPackageNames = array_diff($allPackageNames, $processedPackageNames);
                $details = implode(', ', array_map(
                    fn (string $packageName): string => $packageName . ' (requires: ' . implode(', ', $packages->get($packageName)?->getRequirements() ?? []) . ')',
                    $unprocessedPackageNames,
                ));

                throw new Exception('Circular dependency detected in packages: ' . $details);
            }
        }

        return $sortedPackages;
    }

    private function finalPackageRank(string $packageName): int
    {
        $rank = array_search($packageName, self::FINAL_PACKAGE_NAMES, true);

        return is_int($rank) ? $rank + 1 : 0;
    }

    private function isComposerOnlyCorePackageName(string $packageName): bool
    {
        return TrustedCorePackages::isCoreRuntimePackage($packageName);
    }
}
