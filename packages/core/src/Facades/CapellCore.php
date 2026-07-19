<?php

declare(strict_types=1);

namespace Capell\Core\Facades;

use BackedEnum;
use Capell\Core\Data\AssetData;
use Capell\Core\Data\DefaultPageData;
use Capell\Core\Data\PackageData;
use Capell\Core\Data\PageTypeData;
use Capell\Core\Data\PageVariationData;
use Capell\Core\Data\VendorAssetData;
use Capell\Core\Enums\AssetEnum;
use Capell\Core\Enums\BlueprintSubjectEnum;
use Capell\Core\Enums\PackageTypeEnum;
use Capell\Core\Enums\VendorAssetEnum;
use Capell\Core\Settings\CoreSettings;
use Capell\Core\Support\CapellCoreManager;
use Capell\Core\Support\Manifest\CapellManifestData;
use Capell\Core\Support\Subscriber\SubscriberRegistry;
use Closure;
use DateInterval;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Facade;

/**
 * @method static void flushOctaneState()
 * @method static ?string getInstalledPrettyVersion(string $packageName)
 * @method static CoreSettings settings()
 * @method static static registerAsset(AssetData $asset)
 * @method static Collection<string, AssetData> getAssets()
 * @method static AssetData getAsset(AssetEnum|string $name)
 * @method static bool hasAsset(string $name)
 * @method static mixed rememberCache(BackedEnum|string $key, Closure $callback, Closure|DateTimeInterface|DateInterval|int|null $ttl = null)
 * @method static mixed getFromCache(string $key)
 * @method static void setToCache(string $key, mixed $value, Closure|DateTimeInterface|DateInterval|int|null $ttl = null)
 * @method static bool cacheExists(string $key)
 * @method static void removeCacheKey(string $key)
 * @method static int incrementCacheKey(string $key)
 * @method static void flushCache()
 * @method static void flushLocalCache()
 * @method static list<string> getCloneableRelations(string $model)
 * @method static void addCloneableRelations(string $model, string $relation)
 * @method static static registerComponent(BackedEnum|string $type, BackedEnum|string $name, string $component)
 * @method static static registerComponents(BackedEnum|string $type, array<mixed> $components)
 * @method static array<mixed> getComponents(BackedEnum|string|null $type = null)
 * @method static string getComponent(BackedEnum|string $type, string $name)
 * @method static array<mixed> getCoreComponents(BackedEnum|string|null $type = null)
 * @method static bool hasComponent(BackedEnum|string $type, string $name)
 * @method static static registerDiscoverableComponents(string $in, ?string $for = null)
 * @method static static discoverComponents(string $in, ?string $for = null)
 * @method static array<mixed> getDiscoverableComponents()
 * @method static bool hasCachedComponents()
 * @method static void cacheComponents()
 * @method static void restoreCachedComponents()
 * @method static void clearCachedComponents()
 * @method static string getComponentCachePath()
 * @method static Collection<string, DefaultPageData> loadDefaultPages()
 * @method static Collection<string, DefaultPageData> getDefaultPages()
 * @method static DefaultPageData getDefaultPage(string $key)
 * @method static self addDefaultPage(string $key, string $label, Closure $callback)
 * @method static void serving(Closure $callback)
 * @method static SubscriberRegistry<object> subscriberManager()
 * @method static void registerModelInterceptor(string $model, string $interceptorClass, BackedEnum|array<mixed>|string|null $key = null, int $priority = 0)
 * @method static void unregisterModelInterceptor(string $model, string $interceptorClass, BackedEnum|array<mixed>|string|null $key = null)
 * @method static void replaceModelInterceptor(string $model, string $oldInterceptorClass, string $newInterceptorClass, BackedEnum|array<mixed>|string|null $key = null, int $priority = 0)
 * @method static TModel createModel<TModel of \Illuminate\Database\Eloquent\Model>(class-string<TModel> $model, BackedEnum|array<mixed>|string $key, callable $persist, string $interceptorInterface)
 * @method static TModel createOrUpdateModel<TModel of \Illuminate\Database\Eloquent\Model>(class-string<TModel> $model, BackedEnum|array<mixed>|string $key, callable $persist, string $interceptorInterface)
 * @method static array<mixed> getInterceptorsForModelAndKey(string $model, BackedEnum|array<mixed>|string|null $key)
 * @method static array<mixed> mergeModelInterceptorData(array<mixed> $defaults, array<mixed> $data)
 * @method static array<string, class-string<Model>> getModels()
 * @method static static registerModels(array<int|string, BackedEnum|class-string<Model>> $models = [])
 * @method static static registerPackage(string $name, PackageTypeEnum $type = \Capell\Core\Enums\PackageTypeEnum::Plugin, ?string $serviceProviderClass = null, ?string $path = null, ?string $version = null, ?string $setting = null, array<mixed> $permissions = [], Closure|string|null $description = null, ?string $setupCommand = null, array<mixed> $setupParams = [], ?string $installCommand = null, array<mixed> $installParams = [], bool $defaultSelected = false)
 * @method static static registerManifestPackage(CapellManifestData $manifest, ?string $version = null)
 * @method static Collection<string, PackageData> getPackages(bool $withoutCore = true, bool $sortByDependencies = false)
 * @method static bool hasPackage(string $name)
 * @method static PackageData getPackage(string $name)
 * @method static bool isPackageInstalled(string $name)
 * @method static bool isPackageEnabled(string $name)
 * @method static bool isPackageAvailable(string $name)
 * @method static Collection<string, PackageData> getInstalledPackages()
 * @method static Collection<string, Collection<int, PackageData>> getPackagesGroupedByProductGroup(?string $tier = null, bool $withoutCore = true)
 * @method static array<mixed> getPackageRequirements(string $name)
 * @method static array<mixed> getMissingRequirements(string $name)
 * @method static bool canInstallPackage(string $name)
 * @method static Collection<string, PackageData> getDependentInstalledPackages(string $name)
 * @method static bool canUninstallPackage(string $name)
 * @method static void forcePackageInstalled(string $name, bool $installed = true)
 * @method static void markPackageInstalled(string $name)
 * @method static void markPackageInstalling(string $name)
 * @method static void markPackageFailed(string $name, string $message)
 * @method static void markPackageDisabled(string $name)
 * @method static void markPackageUninstalled(string $name)
 * @method static bool arePackageRequirementsInstalled(array<mixed> $requirements)
 * @method static void clearPackages()
 * @method static void clearExtensionCache()
 * @method static array<mixed> getInstalledExtensionNames()
 * @method static static registerPageType(PageTypeData $type)
 * @method static Collection<string, PageTypeData> getPageTypes()
 * @method static PageTypeData getPageType(BlueprintSubjectEnum|string $name)
 * @method static bool hasPageType(BlueprintSubjectEnum|string $name)
 * @method static static registerPageVariation(PageVariationData $pageData)
 * @method static ?PageVariationData getPageVariation(string $name)
 * @method static bool hasPageVariation(?string $name)
 * @method static array<string, PageVariationData> getPageVariations()
 * @method static list<string> getPageVariationNames()
 * @method static list<class-string<Model>> getPageVariationModels()
 * @method static void registerProtectedTable(Closure|string $table)
 * @method static array<mixed> getProtectedTables()
 * @method static static registerVendorAsset(VendorAssetData $asset)
 * @method static bool hasVendorAssets(VendorAssetEnum $type)
 * @method static Collection<int, VendorAssetData> getVendorAssetsForType(VendorAssetEnum $type)
 * @method static Collection<string, array<int, VendorAssetData>> getAllVendorAssets()
 *
 * @mixin CapellCoreManager
 */
class CapellCore extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return CapellCoreManager::class;
    }
}
