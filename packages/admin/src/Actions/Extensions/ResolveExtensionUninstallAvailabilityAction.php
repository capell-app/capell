<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Extensions;

use Capell\Admin\Data\Extensions\ExtensionUninstallAvailabilityData;
use Capell\Admin\Filament\Pages\ExtensionsPage;
use Capell\Core\Data\PackageData;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\CapellExtension;
use Illuminate\Support\Collection;
use Lorisleiva\Actions\Concerns\AsAction;

final class ResolveExtensionUninstallAvailabilityAction
{
    use AsAction;

    /** @var array<string, ExtensionUninstallAvailabilityData> */
    private array $resolved = [];

    /** @var array<string, list<PackageData>>|null */
    private ?array $dependentPackagesByRequirement = null;

    public function handle(string $packageName, ?PackageData $package = null, ?bool $installed = null): ExtensionUninstallAvailabilityData
    {
        $package ??= CapellCore::hasPackage($packageName) ? CapellCore::getPackage($packageName) : null;
        $installed ??= $package instanceof PackageData
            ? CapellCore::isPackageInstalled($packageName)
            : CapellExtension::query()->where('composer_name', $packageName)->exists();

        $cacheKey = implode('|', [
            $packageName,
            $package instanceof PackageData ? 'resolved' : 'missing',
            $installed ? 'installed' : 'not-installed',
            ExtensionsPage::canManageExtensions() ? 'can-manage' : 'cannot-manage',
        ]);

        if (isset($this->resolved[$cacheKey])) {
            return $this->resolved[$cacheKey];
        }

        if (app()->bound('request')) {
            $requestCache = request()->attributes->get('capell.extension_uninstall_availability', []);

            if (is_array($requestCache) && isset($requestCache[$cacheKey]) && $requestCache[$cacheKey] instanceof ExtensionUninstallAvailabilityData) {
                return $requestCache[$cacheKey];
            }
        }

        $visible = ExtensionsPage::canManageExtensions() && $installed;
        $dependentPackages = $package instanceof PackageData ? $this->dependentPackages($packageName) : [];
        $dependentPackageNames = array_map(fn (PackageData $package): string => $package->name, $dependentPackages);
        $dependentPackageLabels = array_map($this->packageLabel(...), $dependentPackages);
        $packageUnavailable = ! $package instanceof PackageData;
        $canRun = $visible && ! $packageUnavailable;
        $requiredConfirmationPackageNames = $dependentPackageNames === [] ? [] : [...$dependentPackageNames, $packageName];
        $blockReason = $this->blockReason($packageUnavailable, $dependentPackageLabels);

        $availability = new ExtensionUninstallAvailabilityData(
            visible: $visible,
            canRun: $canRun,
            dependentPackages: $dependentPackageLabels,
            dependentPackageNames: $dependentPackageNames,
            requiredConfirmationPackageNames: $requiredConfirmationPackageNames,
            uninstallPackageNames: [...$dependentPackageNames, $packageName],
            blockReason: $blockReason,
            tooltip: $this->tooltip($canRun, $blockReason),
            modalDescription: $this->modalDescription($canRun, $packageUnavailable, $dependentPackageLabels),
            showRemovalModeForm: $canRun,
            requiresDependentConfirmation: $dependentPackageNames !== [],
        );

        $this->resolved[$cacheKey] = $availability;

        if (app()->bound('request')) {
            $requestCache = request()->attributes->get('capell.extension_uninstall_availability', []);
            $requestCache = is_array($requestCache) ? $requestCache : [];
            $requestCache[$cacheKey] = $availability;

            request()->attributes->set('capell.extension_uninstall_availability', $requestCache);
        }

        return $availability;
    }

    /** @return list<PackageData> */
    private function dependentPackages(string $packageName): array
    {
        return array_values($this->dependentPackagesInUninstallOrder($packageName)
            ->mapWithKeys(fn (PackageData $package): array => [$package->name => $package])
            ->values()
            ->all());
    }

    /**
     * @return Collection<int, PackageData>
     */
    private function dependentPackagesInUninstallOrder(string $packageName): Collection
    {
        return $this->directDependentInstalledPackages($packageName)
            ->flatMap(fn (PackageData $dependentPackage): array => [
                ...$this->dependentPackagesInUninstallOrder($dependentPackage->name)->all(),
                $dependentPackage,
            ]);
    }

    /**
     * @return Collection<int, PackageData>
     */
    private function directDependentInstalledPackages(string $packageName): Collection
    {
        return collect($this->dependentPackagesByRequirement()[$packageName] ?? []);
    }

    /**
     * @return array<string, list<PackageData>>
     */
    private function dependentPackagesByRequirement(): array
    {
        if ($this->dependentPackagesByRequirement !== null) {
            return $this->dependentPackagesByRequirement;
        }

        $dependents = [];

        foreach (CapellCore::getInstalledPackages() as $package) {
            foreach ($package->getRequirements() as $requirement) {
                $dependents[$requirement] ??= [];
                $dependents[$requirement][] = $package;
            }
        }

        return $this->dependentPackagesByRequirement = $dependents;
    }

    private function packageLabel(PackageData $package): string
    {
        return $package->getShortName() . ' (' . $package->name . ')';
    }

    /**
     * @param  list<string>  $dependentPackages
     */
    private function blockReason(bool $packageUnavailable, array $dependentPackages): ?string
    {
        if ($dependentPackages !== []) {
            return trans_choice('capell-admin::generic.extension_uninstall_blocked_by_dependents', count($dependentPackages), [
                'extensions' => implode(', ', $dependentPackages),
            ]);
        }

        if ($packageUnavailable) {
            return __('capell-admin::generic.extension_uninstall_blocked_package_unavailable');
        }

        return null;
    }

    private function tooltip(bool $canRun, ?string $blockReason): string
    {
        if ($canRun) {
            return __('capell-admin::button.uninstall_extension');
        }

        if ($blockReason !== null) {
            return $blockReason;
        }

        return __('capell-admin::generic.extension_uninstall_blocked');
    }

    /**
     * @param  list<string>  $dependentPackages
     */
    private function modalDescription(bool $canRun, bool $packageUnavailable, array $dependentPackages): string
    {
        if ($dependentPackages !== []) {
            return trans_choice('capell-admin::generic.extension_uninstall_blocked_modal_dependents', count($dependentPackages), [
                'extensions' => implode(', ', $dependentPackages),
            ]);
        }

        if ($canRun) {
            return __('capell-admin::generic.uninstall_extension_description');
        }

        if ($packageUnavailable) {
            return __('capell-admin::generic.extension_uninstall_blocked_package_unavailable');
        }

        return __('capell-admin::generic.extension_uninstall_blocked');
    }
}
