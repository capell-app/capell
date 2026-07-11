<?php

declare(strict_types=1);

namespace Capell\Core\Concerns;

use Capell\Core\Actions\ResolveExtensionRuntimeGateAction;
use Capell\Core\Data\ExtensionRuntimeGateData;
use Capell\Core\Data\PackageData;
use Capell\Core\Enums\CacheEnum;
use Capell\Core\Enums\ExtensionStatusEnum;
use Capell\Core\Enums\PackageScopeEnum;
use Capell\Core\Enums\PackageTypeEnum;
use Capell\Core\Models\CapellExtension;
use Capell\Core\Providers\CapellServiceProvider;
use Capell\Core\Support\Database\RuntimeSchemaState;
use Capell\Core\Support\Extensions\InstalledExtensionRepository;
use Capell\Core\Support\Install\PackageWorkflowPlanner;
use Capell\Core\Support\Manifest\CapellManifestData;
use Capell\Core\Support\Packages\TrustedCorePackages;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use Throwable;

trait HasPackages
{
    use HasCache;

    /** @var array<string, PackageData> */
    protected array $packages = [];

    /** @var array<string, bool> */
    protected array $forcedPackageInstallStates = [];

    /** @var array<int, string>|null */
    private ?array $installedExtensionNamesCache = null;

    /** @var array<string, ExtensionStatusEnum|null> */
    private array $extensionStatusCache = [];

    /** @var array<string, ExtensionRuntimeGateData|null> */
    private array $extensionRuntimeGateCache = [];

    /** @var array<string, CapellExtension|null> */
    private array $extensionRecordCache = [];

    private bool $extensionRecordsPreloaded = false;

    /**
     * Legacy provider bootstrap registration.
     *
     * Extension packages should publish manifest v3 metadata and be registered through
     * registerManifestPackage(); provider-side metadata registration remains callable
     * only for trusted first-party bootstrap and compatibility tests.
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
        if ($this->hasPackage($manifest->name)) {
            return $this;
        }

        $installed = $this->packages[$manifest->name]->installed ?? $this->forcedPackageInstallStates[$manifest->name] ?? null;

        $this->packages[$manifest->name] = new PackageData(
            name: $manifest->name,
            type: $this->packageTypeFromManifestKind($manifest->kind),
            scopes: array_map(PackageScopeEnum::from(...), $manifest->scopes),
            serviceProviderClass: $this->serviceProviderClassFromManifest($manifest),
            path: $manifest->installPath,
            shortName: $manifest->displayName,
            description: $manifest->description,
            sort: $manifest->order,
            installCommand: is_string($manifest->commands['install'] ?? null) ? $manifest->commands['install'] : null,
            installAction: $this->classStringFromManifest($manifest->actions['install'] ?? null),
            installParams: is_array($manifest->commands['installParams'] ?? null) ? array_values($manifest->commands['installParams']) : [],
            setupCommand: is_string($manifest->commands['setup'] ?? null) ? $manifest->commands['setup'] : null,
            setupAction: $this->classStringFromManifest($manifest->actions['setup'] ?? null),
            setupParams: is_array($manifest->commands['setupParams'] ?? null) ? array_values($manifest->commands['setupParams']) : [],
            demoCommand: is_string($manifest->commands['demo'] ?? null) ? $manifest->commands['demo'] : null,
            demoParams: is_array($manifest->commands['demoParams'] ?? null) ? array_values($manifest->commands['demoParams']) : [],
            setting: $manifest->settings[0] ?? null,
            version: $version ?? $manifest->version,
            requirements: $manifest->requires,
            productGroup: $manifest->productGroup,
            tier: $manifest->tier,
            bundle: $manifest->bundle,
            core: TrustedCorePackages::contains($manifest->name),
            defaultSelected: $manifest->defaultSelected,
            demo: $manifest->demo,
            afterInstallCommand: is_string($manifest->commands['afterInstall'] ?? null) ? $manifest->commands['afterInstall'] : null,
            afterInstallAction: $this->classStringFromManifest($manifest->actions['afterInstall'] ?? null),
            afterInstallParams: is_array($manifest->commands['afterInstallParams'] ?? null) ? array_values($manifest->commands['afterInstallParams']) : [],
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
        );
        $this->packages[$manifest->name]->installed = $installed;

        return $this;
    }

    /**
     * @return Collection<string, PackageData>
     */
    public function getPackages(bool $withoutCore = true, bool $sortByDependencies = false): Collection
    {
        $sorted = collect($this->packages)
            ->when($withoutCore, fn (Collection $collection): Collection => $collection->reject(
                fn (PackageData $package): bool => $package->name === CapellServiceProvider::$packageName,
            ));

        if ($sortByDependencies) {
            $sorted = resolve(PackageWorkflowPlanner::class)->order($sorted);
        } else {
            $sorted = $sorted
                ->sortBy(fn (PackageData $package): int => $package->getSort())
                ->values();
        }

        $sortedArray = $sorted->all();

        /** @var Collection<string, PackageData> $result */
        $result = collect($sortedArray)
            ->keyBy(fn (PackageData $package): string => $package->name);

        return $result;
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

        if ($this->extensionStatus($name) === ExtensionStatusEnum::Uninstalled) {
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

        $extensionStatus = $this->extensionStatus($name);

        if (in_array($extensionStatus, [
            ExtensionStatusEnum::Disabled,
            ExtensionStatusEnum::Failed,
            ExtensionStatusEnum::Uninstalled,
        ], true)) {
            return false;
        }

        if ($extensionStatus === ExtensionStatusEnum::Enabled) {
            return $this->extensionRuntimeGateAllows($name) !== false;
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

    public function forcePackageInstalled(string $name, bool $installed = true): void
    {
        $this->forcedPackageInstallStates[$name] = $installed;

        if (! $this->hasPackage($name)) {
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
            $this->deletePackageLifecycleRecord($name);
            $this->clearExtensionCache();

            return;
        }

        $this->recordPackageInstalled($name);
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

        $this->recordPackageLifecycle($name, ExtensionStatusEnum::Installing);
        $this->clearExtensionCache();
        $this->forcePackageInstalled($name, false);
    }

    public function markPackageFailed(string $name, string $message): void
    {
        if ($this->isCorePackageName($name)) {
            $this->clearExtensionCache();

            return;
        }

        $this->recordPackageLifecycle($name, ExtensionStatusEnum::Failed, ['install_error' => $message]);
        $this->clearExtensionCache();
        $this->forcePackageInstalled($name, false);
    }

    public function markPackageDisabled(string $name): void
    {
        if ($this->isCorePackageName($name)) {
            $this->clearExtensionCache();

            return;
        }

        $this->recordPackageLifecycle($name, ExtensionStatusEnum::Disabled);
        $this->clearExtensionCache();
        $this->forcePackageInstalled($name, false);
    }

    public function markPackageUninstalled(string $name): void
    {
        if ($this->isCorePackageName($name)) {
            $this->recordPackageLifecycle($name, ExtensionStatusEnum::Uninstalled);

            $names = collect($this->getUninstalledExtensionNames())
                ->push($name)
                ->unique()
                ->values()
                ->all();

            $this->setUninstalledExtensionNames($names);
            $this->clearExtensionCache();

            return;
        }

        $this->recordPackageUninstalled($name);
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
        $this->extensionStatusCache = [];
        $this->extensionRuntimeGateCache = [];
        $this->extensionRecordCache = [];
        $this->extensionRecordsPreloaded = false;
        resolve(RuntimeSchemaState::class)->forgetTable('capell_extensions');
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
        $this->extensionStatusCache = [];
        $this->extensionRuntimeGateCache = [];
        $this->extensionRecordCache = [];
        $this->extensionRecordsPreloaded = false;
        resolve(RuntimeSchemaState::class)->forgetTable('capell_extensions');
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

    /**
     * @return class-string<ServiceProvider>|null
     */
    private function serviceProviderClassFromManifest(CapellManifestData $manifest): ?string
    {
        $provider = $manifest->providers->all()[0] ?? null;

        if (! is_string($provider) || ! is_subclass_of($provider, ServiceProvider::class)) {
            return null;
        }

        /** @var class-string<ServiceProvider> $provider */
        return $provider;
    }

    /**
     * @return class-string|null
     */
    private function classStringFromManifest(mixed $class): ?string
    {
        if (! is_string($class) || ! class_exists($class)) {
            return null;
        }

        /** @var class-string $class */
        return $class;
    }

    private function recordPackageInstalled(string $name): void
    {
        if (! app()->bound('db') || ! $this->capellExtensionsTableExists(refresh: true)) {
            return;
        }

        $package = $this->hasPackage($name) ? $this->getPackage($name) : null;
        $existingExtension = CapellExtension::query()
            ->where('composer_name', $name)
            ->first();

        CapellExtension::query()->updateOrCreate(
            ['composer_name' => $name],
            [
                'name' => $package?->getShortName(),
                'description' => $package?->getDescription(),
                'version' => $package?->version,
                'source' => $package?->path !== null ? 'local' : 'composer',
                'status' => ExtensionStatusEnum::Enabled,
                'enabled_at' => $existingExtension->enabled_at ?? now(),
                'disabled_at' => null,
                'failed_at' => null,
                'installed_at' => $existingExtension->installed_at ?? now(),
                'metadata' => [
                    'product_group' => $package?->getProductGroup(),
                    'tier' => $package?->getTier(),
                    'kind' => $package?->getKind(),
                ],
            ],
        );
    }

    private function extensionStatus(string $name): ?ExtensionStatusEnum
    {
        if (array_key_exists($name, $this->extensionStatusCache)) {
            return $this->extensionStatusCache[$name];
        }

        if (! app()->bound('db') || ! $this->capellExtensionsTableExists()) {
            return null;
        }

        $extension = $this->extensionRecord($name);

        if (! $extension instanceof CapellExtension) {
            return null;
        }

        return $this->extensionStatusCache[$name] = $extension->status;
    }

    private function extensionRuntimeGateAllows(string $name): ?bool
    {
        if (array_key_exists($name, $this->extensionRuntimeGateCache)) {
            return $this->extensionRuntimeGateCache[$name]?->allowed;
        }

        if (! app()->bound('db') || ! $this->capellExtensionsTableExists()) {
            $this->extensionRuntimeGateCache[$name] = null;

            return null;
        }

        $extension = $this->extensionRecord($name);

        if (! $extension instanceof CapellExtension) {
            $this->extensionRuntimeGateCache[$name] = null;

            return null;
        }

        $this->extensionRuntimeGateCache[$name] = ResolveExtensionRuntimeGateAction::run($extension);

        return $this->extensionRuntimeGateCache[$name]?->allowed;
    }

    private function extensionRecord(string $name): ?CapellExtension
    {
        if (array_key_exists($name, $this->extensionRecordCache)) {
            return $this->extensionRecordCache[$name];
        }

        $this->preloadExtensionRecords();

        if (array_key_exists($name, $this->extensionRecordCache)) {
            return $this->extensionRecordCache[$name];
        }

        if (! app()->bound('db') || ! $this->capellExtensionsTableExists()) {
            return null;
        }

        try {
            return $this->extensionRecordCache[$name] = CapellExtension::query()
                ->where('composer_name', $name)
                ->first();
        } catch (Throwable) {
            return null;
        }
    }

    private function preloadExtensionRecords(): void
    {
        if ($this->extensionRecordsPreloaded) {
            return;
        }

        if (! app()->bound('db') || ! $this->capellExtensionsTableExists()) {
            return;
        }

        $packageNames = collect($this->packages)
            ->reject(fn (PackageData $package): bool => $package->isCore())
            ->map(fn (PackageData $package): string => $package->name)
            ->values()
            ->all();

        if ($packageNames === []) {
            return;
        }

        try {
            $extensions = CapellExtension::query()
                ->whereIn('composer_name', $packageNames)
                ->get()
                ->keyBy('composer_name');

            foreach ($packageNames as $packageName) {
                $extension = $extensions->get($packageName);
                $this->extensionRecordCache[$packageName] = $extension instanceof CapellExtension ? $extension : null;
            }

            $this->extensionRecordsPreloaded = true;
        } catch (Throwable) {
            $this->extensionRecordCache = [];
        }
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function recordPackageLifecycle(string $name, ExtensionStatusEnum $status, array $metadata = []): void
    {
        if (! app()->bound('db') || ! $this->capellExtensionsTableExists(refresh: true)) {
            return;
        }

        $package = $this->hasPackage($name) ? $this->getPackage($name) : null;
        $existingExtension = CapellExtension::query()
            ->where('composer_name', $name)
            ->first();

        $existingMetadata = is_array($existingExtension?->metadata) ? $existingExtension->metadata : [];

        CapellExtension::query()->updateOrCreate(
            ['composer_name' => $name],
            [
                'name' => $package?->getShortName(),
                'description' => $package?->getDescription(),
                'version' => $package?->version,
                'source' => $package?->path !== null ? 'local' : 'composer',
                'status' => $status,
                'enabled_at' => null,
                'disabled_at' => $status === ExtensionStatusEnum::Disabled ? now() : null,
                'failed_at' => $status === ExtensionStatusEnum::Failed ? now() : null,
                'installed_at' => $existingExtension?->installed_at,
                'metadata' => array_merge($existingMetadata, [
                    'product_group' => $package?->getProductGroup(),
                    'tier' => $package?->getTier(),
                    'kind' => $package?->getKind(),
                ], $metadata),
            ],
        );
    }

    private function recordPackageUninstalled(string $name): void
    {
        if (! app()->bound('db') || ! $this->capellExtensionsTableExists(refresh: true)) {
            return;
        }

        $this->deletePackageLifecycleRecord($name);
    }

    private function deletePackageLifecycleRecord(string $name): void
    {
        if (! app()->bound('db') || ! $this->capellExtensionsTableExists(refresh: true)) {
            return;
        }

        CapellExtension::query()
            ->where('composer_name', $name)
            ->delete();
    }

    private function capellExtensionsTableExists(bool $refresh = false): bool
    {
        return resolve(RuntimeSchemaState::class)->hasTable('capell_extensions', refresh: $refresh);
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
        if (! app()->bound('cache')) {
            return [];
        }

        $names = $this->getFromCache(CacheEnum::ExtensionUninstalledNames->value);

        if ($names instanceof Collection) {
            $names = $names->toArray();
        }

        return is_array($names)
            ? array_values(array_filter($names, fn (mixed $name): bool => is_string($name) && $name !== ''))
            : [];
    }

    /**
     * @param  array<int, string>  $names
     */
    private function setUninstalledExtensionNames(array $names): void
    {
        $this->setToCache(CacheEnum::ExtensionUninstalledNames->value, array_values($names), ttl: 0);
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
