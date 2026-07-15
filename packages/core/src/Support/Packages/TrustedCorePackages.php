<?php

declare(strict_types=1);

namespace Capell\Core\Support\Packages;

final class TrustedCorePackages
{
    private const array PACKAGE_NAMES = [
        'capell-app/capell',
        'capell-app/core',
        'capell-app/admin',
        'capell-app/frontend',
        'capell-app/installer',
        'capell-app/marketplace',
    ];

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
