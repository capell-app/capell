<?php

declare(strict_types=1);

namespace Capell\Core\Actions;

use Capell\Core\Data\PackageData;
use Capell\Core\Enums\PackageTypeEnum;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Support\Marketplace\MarketplaceAssetUrl;
use Capell\Core\Support\Packages\TrustedCorePackages;
use Capell\Core\Support\Plugins\PluginPackagesFetcher;
use Illuminate\Support\Collection;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * @method static Collection<string, PackageData> run(?string $filter = null)
 */
class GetPluginsAction
{
    use AsObject;

    /**
     * @return Collection<string, PackageData>
     */
    public function handle(?string $filter = null): Collection
    {
        /** @var PluginPackagesFetcher $fetcher */
        $fetcher = resolve(PluginPackagesFetcher::class);
        $remote = $fetcher->getCached();
        if ($remote->isEmpty()) {
            $remote = $fetcher->fetch();
        }

        $packages = CapellCore::getPackages();

        return $this->mergeInstalledAndRemotePackages($packages, $remote, $filter);
    }

    /**
     * @param  Collection<string, PackageData>  $getPackages
     * @param  Collection<int, array<string, mixed>>  $remote
     * @return Collection<string, PackageData>
     */
    private function mergeInstalledAndRemotePackages(Collection $getPackages, Collection $remote, ?string $filter = null): Collection
    {
        $registeredPlugins = $getPackages->filter(fn (PackageData $package): bool => in_array($package->type, [
            PackageTypeEnum::Plugin,
            PackageTypeEnum::Theme,
        ], true));
        $registeredPluginsByName = $registeredPlugins->keyBy(fn (PackageData $package): string => $package->name);

        $installedPlugins = $registeredPlugins->filter(fn (PackageData $package): bool => $package->isInstalled());
        $installedPluginsByName = $installedPlugins->keyBy(fn (PackageData $package): string => $package->name);

        $remotePackages = PackageData::collect(
            $remote
                ->filter(
                    fn (array $package): bool => isset($package['name']) &&
                        (! isset($package['type']) || in_array($package['type'], [
                            PackageTypeEnum::Plugin->value,
                            PackageTypeEnum::Theme->value,
                        ], true) || (
                            $package['type'] === PackageTypeEnum::Package->value
                            && $this->booleanValue($package['defaultSelected'] ?? false)
                        )),
                )
                ->map(fn (array $package): array => [
                    'name' => $package['name'],
                    'key' => $package['name'],
                    'type' => match ($package['type'] ?? null) {
                        PackageTypeEnum::Theme->value => PackageTypeEnum::Theme,
                        PackageTypeEnum::Package->value => PackageTypeEnum::Package,
                        default => PackageTypeEnum::Plugin,
                    },
                    'requirements' => $package['requirements'] ?? null,
                    'supportingPackages' => $package['supportingPackages'] ?? $package['supports'] ?? [],
                    'version' => $package['version'] ?? null,
                    'description' => $package['description'] ?? null,
                    'productGroup' => $package['productGroup'] ?? null,
                    'tier' => $package['tier'] ?? null,
                    'bundle' => $package['bundle'] ?? null,
                    'defaultSelected' => $this->booleanValue($package['defaultSelected'] ?? false),
                    'kind' => $package['kind'] ?? $package['type'] ?? null,
                    'themeKey' => $package['themeKey'] ?? null,
                    'extendsPackage' => $package['extends'] ?? $package['extendsPackage'] ?? null,
                    'previewImageUrl' => MarketplaceAssetUrl::toUrl($this->stringValue($package['previewImageUrl'] ?? $package['preview_image_url'] ?? $package['image_url'] ?? $package['logo_url'] ?? null)),
                    'visibility' => $package['visibility'] ?? 'catalogue',
                ])
                ->all(),
            Collection::class,
        );
        $remoteByName = $remotePackages->keyBy(fn (PackageData $package): string => $package->name);
        $trustedDownloadablePackages = collect(TrustedCorePackages::defaultInstallSelectionNames())
            ->reject(fn (string $packageName): bool => $registeredPluginsByName->has($packageName) || $remoteByName->has($packageName))
            ->map(fn (string $packageName): PackageData => $this->trustedCorePackage($packageName))
            ->keyBy(fn (PackageData $package): string => $package->name);

        $downloadablePackages = $remoteByName
            ->merge($trustedDownloadablePackages)
            ->reject(fn (PackageData $package): bool => $installedPluginsByName->has($package->name))
            ->values();

        return match ($filter) {
            'download' => $remoteByName
                ->merge($trustedDownloadablePackages)
                ->reject(fn (PackageData $package): bool => $registeredPluginsByName->has($package->name)),
            'install' => $installedPlugins,
            default => $registeredPlugins->values()->merge($downloadablePackages)->keyBy(fn (PackageData $package): string => $package->name),
        };
    }

    private function trustedCorePackage(string $packageName): PackageData
    {
        return new PackageData(
            name: $packageName,
            type: PackageTypeEnum::Package,
            description: match ($packageName) {
                'capell-app/admin' => 'Filament admin panel, resources, dashboards, settings UI, users, page editing, and media UI.',
                'capell-app/frontend' => 'Public frontend rendering, routes, themes, cache-aware page delivery, and site output.',
                'capell-app/marketplace' => 'Extension marketplace account linking, catalogue install authorization, and package operations.',
                default => null,
            },
            defaultSelected: TrustedCorePackages::isDefaultInstallSelection($packageName),
            kind: 'package',
        );
    }

    private function booleanValue(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value) || is_int($value)) {
            return filter_var($value, FILTER_VALIDATE_BOOLEAN);
        }

        return false;
    }

    private function stringValue(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
