<?php

declare(strict_types=1);

namespace Capell\Core\Support\Packages;

use Capell\Core\Data\PackageData;

final class TrustedCorePackages
{
    /**
     * The foundation aggregate, mirroring the `replace` block in the root composer.json.
     *
     * Every default install selection name is an aggregate member, so the two lists agree; a
     * package outside the aggregate can still ship pre-selected through its manifest.
     */
    private const array PACKAGE_NAMES = [
        'capell-app/capell',
        'capell-app/core',
        'capell-app/admin',
        'capell-app/frontend',
        'capell-app/installer',
        'capell-app/marketplace',
    ];

    /**
     * Aggregate members pre-ticked in the installer's core selection. A package outside the
     * aggregate opts in to a suite's defaults by declaring `defaultSelected` in its manifest, so
     * Core never names it; see {@see self::isDefaultInstallSelectionPackage()}. The core
     * selection itself stays limited to this list and the installer's configured defaults.
     */
    private const array DEFAULT_INSTALL_SELECTION_NAMES = [
        'capell-app/admin',
        'capell-app/frontend',
        'capell-app/marketplace',
    ];

    private const array CORE_RUNTIME_PACKAGE_NAMES = [
        'capell-app/capell',
        'capell-app/core',
    ];

    public static function contains(string $packageName): bool
    {
        return in_array($packageName, self::PACKAGE_NAMES, true);
    }

    public static function isDefaultInstallSelection(string $packageName): bool
    {
        return in_array($packageName, self::DEFAULT_INSTALL_SELECTION_NAMES, true);
    }

    /**
     * Whether the installer pre-ticks this package: an aggregate member that ships selected, or
     * any package whose manifest or catalogue entry declares `defaultSelected`.
     */
    public static function isDefaultInstallSelectionPackage(PackageData $package): bool
    {
        return $package->defaultSelected === true
            || self::isDefaultInstallSelection($package->name);
    }

    /**
     * Whether the package sits under the vendor that owns the foundation aggregate. Only that
     * vendor's packages may be installed automatically because a catalogue entry claims a role.
     */
    public static function isFoundationVendorPackage(string $packageName): bool
    {
        return str_starts_with($packageName, explode('/', self::PACKAGE_NAMES[0], 2)[0] . '/');
    }

    public static function isCoreRuntimePackage(string $packageName): bool
    {
        return in_array($packageName, self::CORE_RUNTIME_PACKAGE_NAMES, true);
    }

    public static function isAdminPackage(string $packageName): bool
    {
        return $packageName === 'capell-app/admin';
    }

    /**
     * @return list<string>
     */
    public static function defaultInstallSelectionNames(): array
    {
        return self::DEFAULT_INSTALL_SELECTION_NAMES;
    }

    /**
     * @return list<string>
     */
    public static function availabilityNames(string $packageName): array
    {
        if ($packageName === 'capell-app/capell') {
            return ['capell-app/capell', 'capell-app/core'];
        }

        return self::contains($packageName) ? [$packageName] : [];
    }
}
