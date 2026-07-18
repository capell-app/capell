<?php

declare(strict_types=1);

namespace Capell\Core\Concerns;

use Capell\Core\Data\PackageData;
use Capell\Core\Enums\CacheEnum;
use Capell\Core\Enums\ExtensionStatusEnum;
use Capell\Core\Enums\PackageScopeEnum;
use Capell\Core\Enums\PackageTypeEnum;
use Capell\Core\Providers\CapellServiceProvider;
use Capell\Core\Support\Extensions\ExtensionLifecycleRepository;
use Capell\Core\Support\Extensions\InstalledExtensionRepository;
use Capell\Core\Support\Install\PackageWorkflowPlanner;
use Capell\Core\Support\Manifest\CapellManifestData;
use Capell\Core\Support\PackageRegistry\CapellPackageRegistry;
use Capell\Core\Support\Packages\TrustedCorePackages;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

trait HasPackages
{
    use HasCache;

    /** @var array<string, PackageData> */
    protected array $packages = [];

    /** @var array<string, bool> */
    protected array $forcedPackageInstallStates = [];

    /** @var array<int, string>|null */
    private ?array $installedExtensionNamesCache = null;

    /** @var array<string, array<string, PackageData>> */
    private array $packagesCache = [];

    /** @var array<int, string>|null */
    private ?array $uninstalledExtensionNamesCache = null;

    private ?ExtensionLifecycleRepository $extensionLifecycleRepository = null;

    /**
     * Attach runtime provider metadata or register a manifest-less test package.
     *
     * @param  class-string<ServiceProvider>  $serviceProviderClass
     * @param  array<int, string>  $permissions
     * @param  array<int, string>  $setupParams
     * @param  array<int, string>  $installParams
     */
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
        $this->packagesCache = [];

        $manifest = $this->hasPackage($name)
            ? null
            : $this->manifestFromPackagePath($path, $name);

        if (! $this->hasPackage($name) && $manifest instanceof CapellManifestData) {
            $this->registerManifestPackage($manifest, $version);
        }

        if ($this->hasPackage($name)) {
            $package = $this->getPackage($name);
            $package->serviceProviderClass = $serviceProviderClass ?? $package->serviceProviderClass;
            $package->path = $path ?? $package->path;
            $package->version = $version ?? $package->version;
            $package->setting = $setting ?? $package->setting;
            $package->permissions = $permissions !== [] ? array_values($permissions) : $package->permissions;
            $package->installCommand = $installCommand ?? $package->installCommand;
            $package->installParams = $installParams !== [] ? array_values($installParams) : $package->installParams;
            $package->setupCommand = $setupCommand ?? $package->setupCommand;
            $package->setupParams = $setupParams !== [] ? array_values($setupParams) : $package->setupParams;
            $package->defaultSelected = $defaultSelected || $package->defaultSelected === true;

            if ($description instanceof Closure) {
                $package->setDescriptionResolver($description);
            } elseif ($description !== null) {
                $package->description = $description;
            }

            return $this;
        }

        $installed = $this->packages[$name]->installed ?? $this->forcedPackageInstallStates[$name] ?? null;

        $package = new PackageData(
            $name,
            type: $type,
            serviceProviderClass: $serviceProviderClass,
            path: $path,
            description: $description instanceof Closure ? null : $description,
            permissions: array_values($permissions),
            installCommand: $installCommand,
            installParams: array_values($installParams),
            setupCommand: $setupCommand,
            setupParams: array_values($setupParams),
            setting: $setting,
            version: $version,
            defaultSelected: $defaultSelected,
        );
        $package->installed = $installed;

        if ($description instanceof Closure) {
            $package->setDescriptionResolver($description);
        }

        $this->packages[$name] = $package;

        return $this;
    }

    public function registerManifestPackage(CapellManifestData $manifest, ?string $version = null): static
    {
        $this->packagesCache = [];
        $this->uninstalledExtensionNamesCache = null;

        $existing = $this->packages[$manifest->name] ?? null;
        $installed = $existing->installed ?? $this->forcedPackageInstallStates[$manifest->name] ?? null;

        $this->packages[$manifest->name] = new PackageData(
            name: $manifest->name,
            type: $this->packageTypeFromManifestKind($manifest->kind),
            scopes: array_map(PackageScopeEnum::from(...), $manifest->scopes),
            serviceProviderClass: $existing->serviceProviderClass ?? $manifest->serviceProviderClass(),
            path: $existing->path ?? $manifest->installPath,
            shortName: $manifest->displayName,
            description: $manifest->description,
            sort: $manifest->order,
            installCommand: $manifest->installCommand(),
            installAction: $manifest->installAction(),
            installParams: $manifest->installParams(),
            uninstallAction: $manifest->uninstallAction(),
            setupCommand: $manifest->setupCommand(),
            setupAction: $manifest->setupAction(),
            setupParams: $manifest->setupParams(),
            demoCommand: $manifest->demoCommand(),
            demoParams: $manifest->demoParams(),
            setting: $manifest->settings[0] ?? null,
            version: $version ?? $existing->version ?? $manifest->version,
            requirements: $manifest->requires,
            productGroup: $manifest->productGroup,
            tier: $manifest->tier,
            bundle: $manifest->bundle,
            core: TrustedCorePackages::contains($manifest->name),
            defaultSelected: $manifest->defaultSelected,
            demo: $manifest->demo,
            afterInstallCommand: $manifest->afterInstallCommand(),
            afterInstallAction: $manifest->afterInstallAction(),
            afterInstallParams: $manifest->afterInstallParams(),
            kind: $manifest->kind,
            themeKey: $manifest->themeKey,
            extendsPackage: $manifest->extends,
            supportingPackages: $manifest->supports,
            conflicts: $manifest->conflicts,
            contributionCount: count($manifest->contributes),
            performanceBudget: $manifest->performance,
            proposedLicense: $manifest->commercial->proposedLicense,
            requestedCertificationStatus: $manifest->commercial->requestedCertification,
            supportPolicy: $manifest->commercial->supportPolicy,
            privateDocsRequested: $manifest->commercial->privateDocsRequested,
            hiddenFromMarketplace: $manifest->marketplaceHidden,
            slug: $manifest->slug,
            visibility: $manifest->visibility,
            documentationUrl: $manifest->documentationUrl,
            manifest: $manifest,
        );
        $this->packages[$manifest->name]->installed = $installed;

        if (app()->bound(CapellPackageRegistry::class)) {
            resolve(CapellPackageRegistry::class)->register($manifest);
        }

        return $this;
    }

    /**
     * @return Collection<string, PackageData>
     */
    public function getPackages(bool $withoutCore = true, bool $sortByDependencies = false): Collection
    {
        $cacheKey = sprintf('%d:%d', (int) $withoutCore, (int) $sortByDependencies);

        if (isset($this->packagesCache[$cacheKey])) {
            return new Collection($this->packagesCache[$cacheKey]);
        }

        /** @var array<string, PackageData> $packages */
        $packages = collect($this->packages)
            ->when($withoutCore, fn (Collection $collection): Collection => $collection->reject(
                fn (PackageData $package): bool => $package->name === CapellServiceProvider::$packageName,
            ))
            ->when(
                $sortByDependencies,
                fn (Collection $collection): Collection => resolve(PackageWorkflowPlanner::class)->order($collection),
                fn (Collection $collection): Collection => $collection
                    ->sortBy(fn (PackageData $package): int => $package->getSort())
                    ->values(),
            )
            ->keyBy(fn (PackageData $package): string => $package->name)
            ->all();

        $this->packagesCache[$cacheKey] = $packages;

        return new Collection($packages);
    }

    public function hasPackage(string $name): bool
    {
        return isset($this->packages[$name]);
    }

    public function getPackage(string $name): PackageData
    {
        throw_unless(isset($this->packages[$name]), InvalidArgumentException::class, sprintf('Package with name %s not found.', $name));

        return $this->packages[$name];
    }

    public function isPackageInstalled(string $name): bool
    {
        if (! $this->hasPackage($name)) {
            return false;
        }

        $package = $this->getPackage($name);

        if (in_array($name, $this->getUninstalledExtensionNames(), true)) {
            return false;
        }

        if ($this->extensionLifecycle()->status($name, collect($this->packages)) === ExtensionStatusEnum::Uninstalled) {
            return false;
        }

        if ($package->isCore()) {
            return $this->corePackageIsAvailable($package);
        }

        if (array_key_exists($name, $this->forcedPackageInstallStates)) {
            return $this->forcedPackageInstallStates[$name];
        }

        return $this->isPackageEnabled($name);
    }

    public function isPackageEnabled(string $name): bool
    {
        if (! $this->hasPackage($name)) {
            return false;
        }

        $package = $this->getPackage($name);

        if ($package->isCore()) {
            return $this->isPackageInstalled($name);
        }

        if (in_array($name, $this->getUninstalledExtensionNames(), true)) {
            return false;
        }

        $extensionStatus = $this->extensionLifecycle()->status($name, collect($this->packages));

        if (in_array($extensionStatus, [
            ExtensionStatusEnum::Disabled,
            ExtensionStatusEnum::Failed,
            ExtensionStatusEnum::Uninstalled,
        ], true)) {
            return false;
        }

        if ($extensionStatus === ExtensionStatusEnum::Enabled) {
            return $this->extensionLifecycle()->runtimeGateAllows($name, collect($this->packages)) !== false;
        }

        if ($package->installed !== null) {
            return $package->installed === true;
        }

        $package->installed = false;

        return $package->installed;
    }

    public function isPackageAvailable(string $name): bool
    {
        if (! $this->hasPackage($name)) {
            return false;
        }

        $package = $this->getPackage($name);

        return resolve(InstalledExtensionRepository::class)->isAvailable(
            composerName: $package->name,
            path: $package->path,
        );
    }

    /**
     * Retrieves the registered packages.
     *
     * @return Collection<string, PackageData>
     */
    public function getInstalledPackages(): Collection
    {
        return $this->getPackages()
            ->filter(fn (PackageData $package): bool => $this->isPackageInstalled($package->name));
    }

    /**
     * @return Collection<string, Collection<int, PackageData>>
     */
    public function getPackagesGroupedByProductGroup(?string $tier = null, bool $withoutCore = true): Collection
    {
        return $this->getPackages(withoutCore: $withoutCore)
            ->when(
                $tier !== null,
                fn (Collection $packages): Collection => $packages->filter(
                    fn (PackageData $package): bool => $package->getTier() === $tier,
                ),
            )
            ->groupBy(fn (PackageData $package): string => $package->getProductGroup())
            ->sortKeys()
            ->map(fn (Collection $packages): Collection => $packages->values());
    }

    /** @return list<string> */
    public function getPackageRequirements(string $name): array
    {
        if (! $this->hasPackage($name)) {
            return [];
        }

        return $this->getPackage($name)->getRequirements();
    }

    /** @return list<string> */
    public function getMissingRequirements(string $name): array
    {
        return array_values(array_filter(
            $this->getPackageRequirements($name),
            fn (string $requirement): bool => ! in_array($requirement, [
                CapellServiceProvider::$packageName,
                'capell-app/core',
            ], true)
                && ! $this->isPackageInstalled($requirement),
        ));
    }

    public function canInstallPackage(string $name): bool
    {
        return $this->hasPackage($name) && $this->getMissingRequirements($name) === [];
    }

    /**
     * @return Collection<string, PackageData>
     */
    public function getDependentInstalledPackages(string $name): Collection
    {
        return $this->getInstalledPackages()->filter(
            fn (PackageData $package): bool => in_array($name, $package->getRequirements(), true),
        );
    }

    public function canUninstallPackage(string $name): bool
    {
        if (! $this->hasPackage($name)) {
            return false;
        }

        return $this->getDependentInstalledPackages($name)->isEmpty();
    }

    /** @internal */
    public function forcePackageInstalled(string $name, bool $installed = true): void
    {
        $this->forcedPackageInstallStates[$name] = $installed;

        if (! $this->hasPackage($name)) {
            $this->packagesCache = [];
            $this->packages[$name] = new PackageData(
                name: $name,
                type: PackageTypeEnum::Plugin,
            );
            $this->packages[$name]->setInstalled($installed);

            return;
        }

        $this->packages[$name]->setInstalled($installed);
    }

    public function markPackageInstalled(string $name): void
    {
        if ($this->isCorePackageName($name)) {
            $this->setUninstalledExtensionNames(
                collect($this->getUninstalledExtensionNames())
                    ->reject(fn (string $packageName): bool => $packageName === $name)
                    ->values()
                    ->all(),
            );
            $this->extensionLifecycle()->delete($name);
            $this->clearExtensionCache();

            return;
        }

        $this->extensionLifecycle()->recordInstalled($name, $this->packageOrNull($name));
        $this->clearExtensionCache();

        $this->setUninstalledExtensionNames(
            collect($this->getUninstalledExtensionNames())
                ->reject(fn (string $packageName): bool => $packageName === $name)
                ->values()
                ->all(),
        );

        $this->forcePackageInstalled($name);
    }

    public function markPackageInstalling(string $name): void
    {
        if ($this->isCorePackageName($name)) {
            $this->clearExtensionCache();

            return;
        }

        $this->extensionLifecycle()->recordLifecycle($name, ExtensionStatusEnum::Installing, $this->packageOrNull($name));
        $this->clearExtensionCache();
        $this->forcePackageInstalled($name, false);
    }

    public function markPackageFailed(string $name, string $message): void
    {
        if ($this->isCorePackageName($name)) {
            $this->clearExtensionCache();

            return;
        }

        $this->extensionLifecycle()->recordLifecycle($name, ExtensionStatusEnum::Failed, $this->packageOrNull($name), ['install_error' => $message]);
        $this->clearExtensionCache();
        $this->forcePackageInstalled($name, false);
    }

    public function markPackageDisabled(string $name): void
    {
        if ($this->isCorePackageName($name)) {
            $this->clearExtensionCache();

            return;
        }

        $this->extensionLifecycle()->recordLifecycle($name, ExtensionStatusEnum::Disabled, $this->packageOrNull($name));
        $this->clearExtensionCache();
        $this->forcePackageInstalled($name, false);
    }

    public function markPackageUninstalled(string $name): void
    {
        if ($this->isCorePackageName($name)) {
            $this->extensionLifecycle()->recordLifecycle($name, ExtensionStatusEnum::Uninstalled, $this->packageOrNull($name));

            $names = collect($this->getUninstalledExtensionNames())
                ->push($name)
                ->unique()
                ->values()
                ->all();

            $this->setUninstalledExtensionNames($names);
            $this->clearExtensionCache();

            return;
        }

        $this->extensionLifecycle()->delete($name);
        $this->clearExtensionCache();

        $names = collect($this->getUninstalledExtensionNames())
            ->push($name)
            ->unique()
            ->values()
            ->all();

        $this->setUninstalledExtensionNames($names);
        $this->forcePackageInstalled($name, false);
    }

    /**
     * Returns true if all requirements for the package are present and installed.
     *
     * @param  array<int, string>  $requirements
     */
    public function arePackageRequirementsInstalled(array $requirements): bool
    {
        foreach ($requirements as $package) {
            if (in_array($package, [
                CapellServiceProvider::$packageName,
                'capell-app/core',
            ], true)) {
                continue;
            }

            if (! $this->hasPackage($package)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Reset the registered-packages list and all installation state.
     * Intended for test teardown or scenarios where the package registry must be rebuilt.
     */
    public function clearPackages(): void
    {
        $this->packages = [];
        $this->forcedPackageInstallStates = [];
        $this->installedExtensionNamesCache = null;
        $this->packagesCache = [];
        $this->uninstalledExtensionNamesCache = null;
        $this->extensionLifecycle()->clear();
    }

    /**
     * Clear the cached list of installed extension packages.
     */
    public function clearExtensionCache(): void
    {
        $this->removeCacheKey(CacheEnum::ExtensionInstalledNames->value);
        $this->removeCacheKey(CacheEnum::ExtensionPackages->value);

        foreach ($this->packages as $packageData) {
            $packageData->installed = null;
        }

        $this->installedExtensionNamesCache = null;
        $this->packagesCache = [];
        $this->uninstalledExtensionNamesCache = null;
        $this->extensionLifecycle()->clear();
    }

    /**
     * Returns the cached list of installed extension package names.
     *
     * @return array<int, string>
     */
    public function getInstalledExtensionNames(): array
    {
        if ($this->installedExtensionNamesCache !== null) {
            return $this->installedExtensionNamesCache;
        }

        if (! app()->bound('cache')) {
            return $this->installedExtensionNamesCache = $this->resolveInstalledExtensionNames();
        }

        $names = $this->rememberCache(
            CacheEnum::ExtensionInstalledNames->value,
            fn (): array => $this->resolveInstalledExtensionNames(),
        ) ?? [];

        if ($names instanceof Collection) {
            $names = $names->toArray();
        }

        $this->installedExtensionNamesCache = $names;

        return $names;
    }

    private function manifestFromPackagePath(?string $packagePath, string $packageName): ?CapellManifestData
    {
        $manifestPath = $packagePath !== null ? $packagePath . '/capell.json' : null;

        if ($manifestPath === null) {
            return null;
        }

        if (! is_file($manifestPath)) {
            return null;
        }

        $contents = file_get_contents($manifestPath);
        $manifest = $contents !== false ? json_decode($contents, true) : null;

        if (! is_array($manifest) || ($manifest['manifest-version'] ?? null) !== 3 || ($manifest['name'] ?? null) !== $packageName) {
            return null;
        }

        return CapellManifestData::fromArray($manifest, realpath($packagePath) ?: $packagePath);
    }

    private function extensionLifecycle(): ExtensionLifecycleRepository
    {
        return $this->extensionLifecycleRepository ??= resolve(ExtensionLifecycleRepository::class);
    }

    private function packageOrNull(string $name): ?PackageData
    {
        return $this->hasPackage($name) ? $this->getPackage($name) : null;
    }

    private function isCorePackageName(string $name): bool
    {
        return $this->hasPackage($name) && $this->getPackage($name)->isCore();
    }

    private function corePackageIsAvailable(PackageData $package): bool
    {
        return array_any(TrustedCorePackages::availabilityNames($package->name), fn (string $composerName) => resolve(InstalledExtensionRepository::class)->isAvailable(
            composerName: $composerName,
            path: $package->path,
        ));
    }

    /**
     * @return array<int, string>
     */
    private function resolveInstalledExtensionNames(): array
    {
        return $this->getPackages(withoutCore: false)
            ->filter(fn (PackageData $package): bool => $this->isPackageInstalled($package->name))
            ->keys()
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function getUninstalledExtensionNames(): array
    {
        if ($this->uninstalledExtensionNamesCache !== null) {
            return $this->uninstalledExtensionNamesCache;
        }

        if (! app()->bound('cache')) {
            return $this->uninstalledExtensionNamesCache = [];
        }

        $names = $this->getFromCache(CacheEnum::ExtensionUninstalledNames->value);

        if ($names instanceof Collection) {
            $names = $names->toArray();
        }

        return $this->uninstalledExtensionNamesCache = is_array($names)
            ? array_values(array_filter($names, fn (mixed $name): bool => is_string($name) && $name !== ''))
            : [];
    }

    /**
     * @param  array<int, string>  $names
     */
    private function setUninstalledExtensionNames(array $names): void
    {
        $names = array_values($names);

        $this->setToCache(CacheEnum::ExtensionUninstalledNames->value, $names, ttl: 0);
        $this->uninstalledExtensionNamesCache = $names;
    }

    private function packageTypeFromManifestKind(string $kind): PackageTypeEnum
    {
        return match ($kind) {
            'integration' => PackageTypeEnum::Integration,
            'theme' => PackageTypeEnum::Theme,
            'plugin' => PackageTypeEnum::Plugin,
            default => PackageTypeEnum::Package,
        };
    }
}
