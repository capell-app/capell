<?php

declare(strict_types=1);

namespace Capell\Core\Support\Packages;

use Capell\Core\Actions\GetPluginsAction;
use Capell\Core\Data\PackageData;
use Capell\Core\Enums\PackageRole;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Support\Plugins\PluginPackagesFetcher;
use Illuminate\Support\Collection;

/**
 * Finds the packages that declare a {@see PackageRole}, so Core acts on what a package does
 * instead of on its name.
 *
 * Installed packages answer from their manifest. A package that is not installed yet answers
 * from the Marketplace catalogue, held in cache unless a caller asks for a refresh, so planning
 * an install never waits on the network here and never guesses a package name.
 *
 * Catalogue entries are publisher-authored. A role that makes Core install a package unprompted
 * (the default theme, developer tooling, the full scaffold base) is therefore honoured from the
 * catalogue only for a package under the foundation vendor, and every candidate needs a valid
 * Composer name because the name is written into Composer arguments and generated manifests.
 */
final class PackageRoleResolver
{
    /** @var list<PackageRole> */
    private const array AUTOMATIC_INSTALL_ROLES = [
        PackageRole::DefaultTheme,
        PackageRole::DeveloperTooling,
        PackageRole::ScaffoldFullProfile,
    ];

    private const string COMPOSER_NAME_PATTERN = '~^[a-z0-9]([_.-]?[a-z0-9]+)*/[a-z0-9](([_.]|-{1,2})?[a-z0-9]+)*$~D';

    /**
     * Packages that declare the role, lowest `rolePriority` first and then by name, so a tie is
     * stable. Pass a collection to search it instead of the installed and catalogue set.
     *
     * @param  Collection<string, PackageData>|null  $packages
     * @return Collection<string, PackageData>
     */
    public function packagesWithRole(PackageRole $role, ?Collection $packages = null, bool $refreshCatalogue = false): Collection
    {
        return ($packages ?? $this->knownPackages($refreshCatalogue))
            ->filter(fn (PackageData $package): bool => $package->hasRole($role) && $this->mayProvideRole($package, $role))
            ->sortBy(static fn (PackageData $package): array => [$package->rolePriority(), $package->name])
            ->keyBy(static fn (PackageData $package): string => $package->name);
    }

    /**
     * The package that holds the role, preferring an installed one over a catalogue entry.
     * Without a collection the catalogue is consulted only when no installed package holds the
     * role, and `$refreshCatalogue` lets a caller that cannot rely on the cache fetch it.
     *
     * @param  Collection<string, PackageData>|null  $packages
     */
    public function packageWithRole(PackageRole $role, ?Collection $packages = null, bool $refreshCatalogue = false): ?PackageData
    {
        if (! $packages instanceof Collection) {
            $installed = $this->packagesWithRole($role, CapellCore::getPackages())
                ->first(static fn (PackageData $package): bool => $package->isInstalled());

            if ($installed instanceof PackageData) {
                return $installed;
            }
        }

        $candidates = $this->packagesWithRole($role, $packages, $refreshCatalogue);

        return $candidates->first(static fn (PackageData $package): bool => $package->isInstalled())
            ?? $candidates->first();
    }

    /**
     * @param  Collection<string, PackageData>|null  $packages
     */
    public function packageNameWithRole(PackageRole $role, ?Collection $packages = null, bool $refreshCatalogue = false): ?string
    {
        return $this->packageWithRole($role, $packages, $refreshCatalogue)?->name;
    }

    /**
     * Names of the installed packages that declare the role.
     *
     * @return list<string>
     */
    public function installedPackageNamesWithRole(PackageRole $role): array
    {
        return array_values($this->packagesWithRole($role, CapellCore::getPackages())
            ->filter(static fn (PackageData $package): bool => $package->isInstalled())
            ->keys()
            ->map(static fn (int|string $name): string => (string) $name)
            ->all());
    }

    /** Whether an installed package declares the role. */
    public function hasInstalledPackageWithRole(PackageRole $role): bool
    {
        return $this->installedPackageNamesWithRole($role) !== [];
    }

    /**
     * Every package Core knows about: those registered from manifests, then catalogue entries for
     * packages that are not registered. A registered package always wins over its catalogue entry.
     * The install steps clear the cached catalogue, so a caller running inside one passes
     * `$refreshCatalogue` to fetch it again; an unreachable source leaves the cached entries.
     *
     * @return Collection<string, PackageData>
     */
    public function knownPackages(bool $refreshCatalogue = false): Collection
    {
        $registered = CapellCore::getPackages();
        $fetcher = resolve(PluginPackagesFetcher::class);
        $catalogue = GetPluginsAction::run('download', $refreshCatalogue ? $fetcher->fetch() : $fetcher->getCached());

        return $catalogue->merge($registered);
    }

    private function mayProvideRole(PackageData $package, PackageRole $role): bool
    {
        if (preg_match(self::COMPOSER_NAME_PATTERN, $package->name) !== 1) {
            return false;
        }

        if ($package->isInstalled()) {
            return true;
        }

        if (! in_array($role, self::AUTOMATIC_INSTALL_ROLES, true)) {
            return true;
        }

        return TrustedCorePackages::isFoundationVendorPackage($package->name);
    }
}
