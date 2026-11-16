<?php

declare(strict_types=1);

namespace Capell\Core\Support\Install;

use Capell\Core\Enums\PackageRole;
use Capell\Core\Support\Packages\PackageRoleResolver;
use Composer\InstalledVersions;

class DeveloperToolingInstallationState
{
    public const string BOOST_PACKAGE_NAME = 'laravel/boost';

    /**
     * The package that declares the developer-tooling role, whether it is installed or only listed
     * in the Marketplace catalogue. Null when nothing Core knows about declares it.
     */
    public function toolingPackageName(): ?string
    {
        return resolve(PackageRoleResolver::class)->packageNameWithRole(PackageRole::DeveloperTooling);
    }

    public function isInstalled(): bool
    {
        return $this->toolingPackageName() !== null && $this->missingPackageNames() === [];
    }

    /**
     * Packages the tooling needs that Composer does not have yet. The developer-tooling package
     * is named only when something declares the role, so an unnamed one is reported by
     * {@see self::isInstalled()} returning false rather than by a guessed name.
     *
     * @return array<int, string>
     */
    public function missingPackageNames(): array
    {
        $required = array_filter([$this->toolingPackageName(), self::BOOST_PACKAGE_NAME]);

        return collect($required)
            ->reject(static fn (string $packageName): bool => InstalledVersions::isInstalled($packageName))
            ->values()
            ->all();
    }
}
