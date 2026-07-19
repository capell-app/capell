<?php

declare(strict_types=1);

namespace Capell\Core\Support;

use Capell\Core\Concerns\HasAssets;
use Capell\Core\Concerns\HasCache;
use Capell\Core\Concerns\HasCloneableRelations;
use Capell\Core\Concerns\HasComponents;
use Capell\Core\Concerns\HasDefaultPages;
use Capell\Core\Concerns\HasEvents;
use Capell\Core\Concerns\HasListeners;
use Capell\Core\Concerns\HasMigrations;
use Capell\Core\Concerns\HasModelInterceptors;
use Capell\Core\Concerns\HasModelRelations;
use Capell\Core\Concerns\HasModels;
use Capell\Core\Concerns\HasPackages;
use Capell\Core\Concerns\HasPageTypes;
use Capell\Core\Concerns\HasPageVariation;
use Capell\Core\Concerns\HasProtectedTables;
use Capell\Core\Concerns\HasVendorAssets;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Octane\Resettable;
use Capell\Core\Providers\CapellServiceProvider;
use Capell\Core\Settings\CoreSettings;
use Capell\Core\Support\Cache\CapellCacheManager;
use Capell\Core\Support\PackageRegistry\CapellPackageRegistry;
use Composer\InstalledVersions;
use RuntimeException;

class CapellCoreManager implements Resettable
{
    use HasAssets;
    use HasCache;
    use HasCloneableRelations;
    use HasComponents;
    use HasDefaultPages;
    use HasEvents;
    use HasListeners;
    use HasMigrations;
    use HasModelInterceptors;
    use HasModelRelations;
    use HasModels;
    use HasPackages;
    use HasPageTypes;
    use HasPageVariation;
    use HasProtectedTables;
    use HasVendorAssets;

    public function flushOctaneState(): void
    {
        resolve(CapellCacheManager::class)->flushLocalCache();
        resolve(CapellPackageRegistry::class)->flushRuntimeState();

        $this->flushComponentRuntimeState();
        $this->flushModelRuntimeState();
        $this->defaultPages = null;
    }

    public function getInstalledPrettyVersion(string $packageName): ?string
    {
        if (InstalledVersions::isInstalled($packageName)) {
            return InstalledVersions::getPrettyVersion($packageName);
        }

        return null;
    }

    public function settings(): CoreSettings
    {
        $settingsClass = CapellCore::getPackage(CapellServiceProvider::$packageName)->setting;

        throw_if(! is_string($settingsClass) || $settingsClass === '', RuntimeException::class, 'Core settings class is not configured.');

        return resolve($settingsClass);
    }
}
