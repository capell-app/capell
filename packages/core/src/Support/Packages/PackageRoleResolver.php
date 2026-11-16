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
 * from the Marketplace catalogue already held in cache, so planning an install never waits on
 * the network here and never guesses a package name.
 */
final class PackageRoleResolver
{
    /**
     * Packages that declare the role, lowest `rolePriority` first and then by name, so a tie is
     * stable. Pass a collection to search it instead of the installed and cached catalogue set.
     *
     * @param  Collection<string, PackageData>|null  $packages
     * @return Collection<string, PackageData>
     */
    public function packagesWithRole(PackageRole $role, ?Collection $packages = null): Collection
    {
        return ($packages ?? $this->knownPackages())
            ->filter(static fn (PackageData $package): bool => $package->hasRole($role))
            ->sortBy(static fn (PackageData $package): array => [$package->rolePriority(), $package->name])
            ->keyBy(static fn (PackageData $package): string => $package->name);
    }

    /**
     * The package that holds the role, preferring an installed one over a catalogue entry.
     *
     * @param  Collection<string, PackageData>|null  $packages
     */
    public function packageWithRole(PackageRole $role, ?Collection $packages = null): ?PackageData
    {
        $candidates = $this->packagesWithRole($role, $packages);

        return $candidates->first(static fn (PackageData $package): bool => $package->isInstalled())
            ?? $candidates->first();
    }

    /**
     * @param  Collection<string, PackageData>|null  $packages
     */
    public function packageNameWithRole(PackageRole $role, ?Collection $packages = null): ?string
    {
        return $this->packageWithRole($role, $packages)?->name;
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
     *
     * @return Collection<string, PackageData>
     */
    public function knownPackages(): Collection
    {
        $registered = CapellCore::getPackages();
        $catalogue = GetPluginsAction::run('download', resolve(PluginPackagesFetcher::class)->getCached());

        return $catalogue->merge($registered);
    }
}
