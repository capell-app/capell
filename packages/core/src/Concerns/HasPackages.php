<?php

declare(strict_types=1);

namespace Capell\Core\Concerns;

use Capell\Core\Data\PackageData;
use Capell\Core\Enums\PackageTypeEnum;
use Capell\Core\Support\Manifest\CapellManifestData;
use Capell\Core\Support\PackageRegistry\CapellPackageRegistry;
use Closure;
use Illuminate\Support\Collection;

trait HasPackages
{
    public function registerPackage(
        string $name,
        PackageTypeEnum $type = PackageTypeEnum::Plugin,
        ?string $serviceProviderClass = null,
        ?string $path = null,
        ?string $version = null,
        ?string $setting = null,
        array $permissions = [],
        string|Closure|null $description = null,
        ?string $setupCommand = null,
        array $setupParams = [],
        ?string $installCommand = null,
        array $installParams = [],
        bool $defaultSelected = false,
    ): static {
        resolve(CapellPackageRegistry::class)->registerPackage($name, $type, $serviceProviderClass, $path, $version, $setting, $permissions, $description, $setupCommand, $setupParams, $installCommand, $installParams, $defaultSelected);

        return $this;
    }

    public function registerManifestPackage(CapellManifestData $manifest, ?string $version = null): static
    {
        resolve(CapellPackageRegistry::class)->registerManifestPackage($manifest, $version);

        return $this;
    }

    public function getPackages(bool $withoutCore = true, bool $sortByDependencies = false): Collection
    {
        return resolve(CapellPackageRegistry::class)->getPackages($withoutCore, $sortByDependencies);
    }

    public function hasPackage(string $name): bool
    {
        return resolve(CapellPackageRegistry::class)->hasPackage($name);
    }

    public function getPackage(string $name): PackageData
    {
        return resolve(CapellPackageRegistry::class)->getPackage($name);
    }

    public function isPackageInstalled(string $name): bool
    {
        return resolve(CapellPackageRegistry::class)->isPackageInstalled($name);
    }

    public function isPackageEnabled(string $name): bool
    {
        return resolve(CapellPackageRegistry::class)->isPackageEnabled($name);
    }

    public function isPackageAvailable(string $name): bool
    {
        return resolve(CapellPackageRegistry::class)->isPackageAvailable($name);
    }

    public function getInstalledPackages(): Collection
    {
        return resolve(CapellPackageRegistry::class)->getInstalledPackages();
    }

    public function getPackagesGroupedByProductGroup(?string $tier = null, bool $withoutCore = true): Collection
    {
        return resolve(CapellPackageRegistry::class)->getPackagesGroupedByProductGroup($tier, $withoutCore);
    }

    public function getPackageRequirements(string $name): array
    {
        return resolve(CapellPackageRegistry::class)->getPackageRequirements($name);
    }

    public function getMissingRequirements(string $name): array
    {
        return resolve(CapellPackageRegistry::class)->getMissingRequirements($name);
    }

    public function canInstallPackage(string $name): bool
    {
        return resolve(CapellPackageRegistry::class)->canInstallPackage($name);
    }

    public function getDependentInstalledPackages(string $name): Collection
    {
        return resolve(CapellPackageRegistry::class)->getDependentInstalledPackages($name);
    }

    public function canUninstallPackage(string $name): bool
    {
        return resolve(CapellPackageRegistry::class)->canUninstallPackage($name);
    }

    public function forcePackageInstalled(string $name, bool $installed = true): void
    {
        resolve(CapellPackageRegistry::class)->forcePackageInstalled($name, $installed);
    }

    public function markPackageInstalled(string $name): void
    {
        resolve(CapellPackageRegistry::class)->markPackageInstalled($name);
    }

    public function markPackageInstalling(string $name): void
    {
        resolve(CapellPackageRegistry::class)->markPackageInstalling($name);
    }

    public function markPackageFailed(string $name, string $message): void
    {
        resolve(CapellPackageRegistry::class)->markPackageFailed($name, $message);
    }

    public function markPackageDisabled(string $name): void
    {
        resolve(CapellPackageRegistry::class)->markPackageDisabled($name);
    }

    public function markPackageUninstalled(string $name): void
    {
        resolve(CapellPackageRegistry::class)->markPackageUninstalled($name);
    }

    public function arePackageRequirementsInstalled(array $requirements): bool
    {
        return resolve(CapellPackageRegistry::class)->arePackageRequirementsInstalled($requirements);
    }

    public function clearPackages(): void
    {
        resolve(CapellPackageRegistry::class)->clearPackages();
    }

    public function clearExtensionCache(): void
    {
        resolve(CapellPackageRegistry::class)->clearExtensionCache();
    }

    public function getInstalledExtensionNames(): array
    {
        return resolve(CapellPackageRegistry::class)->getInstalledExtensionNames();
    }
}
