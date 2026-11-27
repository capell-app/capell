<?php

declare(strict_types=1);

namespace Capell\Core\Actions;

use Capell\Core\Data\Manifest\ExtensionInstallTipData;
use Capell\Core\Data\Manifest\ExtensionLegacyTableData;
use Capell\Core\Data\PackageData;
use Capell\Core\Enums\PackageRole;
use Capell\Core\Enums\PackageScopeEnum;
use Capell\Core\Enums\PackageTypeEnum;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Support\Marketplace\MarketplaceAssetUrl;
use Capell\Core\Support\Packages\BundledFoundationCatalogue;
use Capell\Core\Support\Packages\TrustedCorePackages;
use Capell\Core\Support\Plugins\PluginPackagesFetcher;
use Illuminate\Support\Collection;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * @method static Collection<string, PackageData> run(?string $filter = null, ?Collection<int, array<string, mixed>> $remote = null)
 */
class GetPluginsAction
{
    use AsFake;
    use AsObject;

    /**
     * @param  Collection<int, array<string, mixed>>|null  $remote
     * @return Collection<string, PackageData>
     */
    public function handle(?string $filter = null, ?Collection $remote = null): Collection
    {
        // An explicit snapshot (including empty/unavailable) is authoritative
        // for this caller, independently of persistent catalogue caching.
        if (! $remote instanceof Collection) {
            $remote = resolve(PluginPackagesFetcher::class)->fetch();
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

        // Bundled foundation entries sit under the response, so a fresh install that cannot read
        // the catalogue still finds the packages it installs unprompted, such as the default theme.
        $remote = BundledFoundationCatalogue::under($remote);

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
                    'isPaid' => is_bool($package['isPaid'] ?? null) ? $package['isPaid'] : null,
                    'installState' => $this->stringValue($package['installState'] ?? null),
                    'installEligibility' => is_array($package['installEligibility'] ?? null) ? $package['installEligibility'] : null,
                    'purchaseUrl' => $this->stringValue($package['purchaseUrl'] ?? null),
                    'slug' => $this->stringValue($package['slug'] ?? null),
                    'bundle' => $package['bundle'] ?? null,
                    'defaultSelected' => $this->booleanValue($package['defaultSelected'] ?? false),
                    'kind' => $package['kind'] ?? $package['type'] ?? null,
                    'themeKey' => $package['themeKey'] ?? null,
                    'extendsPackage' => $package['extends'] ?? $package['extendsPackage'] ?? null,
                    'previewImageUrl' => MarketplaceAssetUrl::toUrl($this->stringValue($package['previewImageUrl'] ?? $package['preview_image_url'] ?? $package['image_url'] ?? $package['logo_url'] ?? null)),
                    'visibility' => $package['visibility'] ?? 'catalogue',
                    'launchStatus' => $package['launchStatus'] ?? 'shipped',
                    'declaredRoles' => $this->declaredRoles($package['roles'] ?? null),
                    'declaredRolePriority' => is_int($package['rolePriority'] ?? null) ? $package['rolePriority'] : null,
                    'declaredInstallTips' => $this->declaredRows($package['installTips'] ?? null, ExtensionInstallTipData::tryFromArray(...)),
                    'declaredLegacyTables' => $this->declaredRows($package['legacyTables'] ?? null, ExtensionLegacyTableData::tryFromArray(...)),
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
            // Its manifest is not on disk until it is downloaded, so the install plan reads the
            // scope that decides whether to build frontend assets from here.
            scopes: $packageName === 'capell-app/frontend' ? [PackageScopeEnum::Frontend] : [],
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

    /** @return list<PackageRole> */
    private function declaredRoles(mixed $roles): array
    {
        if (! is_array($roles)) {
            return [];
        }

        return array_values(array_unique(
            array_filter(array_map(
                static fn (mixed $role): ?PackageRole => is_string($role) ? PackageRole::tryFrom($role) : null,
                $roles,
            )),
            SORT_REGULAR,
        ));
    }

    /**
     * Catalogue rows are untrusted, so a malformed row is dropped instead of aborting discovery
     * for every package in the response.
     *
     * @template TRow of object
     *
     * @param  callable(array<string, mixed>): ?TRow  $factory
     * @return list<TRow>
     */
    private function declaredRows(mixed $rows, callable $factory): array
    {
        if (! is_array($rows)) {
            return [];
        }

        return array_values(array_filter(
            array_map($factory, array_filter($rows, is_array(...))),
            static fn (?object $row): bool => $row !== null,
        ));
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
