<?php

declare(strict_types=1);

namespace Capell\Core\Support\Extensions;

use Capell\Core\Support\PackageRegistry\CapellPackageRegistry;

/**
 * Answers whether a Capell extension is present and switched on, so callers
 * stop asking Composer directly. Composer only knows that files are on disk;
 * an extension that is installed but disabled, uninstalled through the
 * lifecycle, or failed at boot must not light up the features that depend on
 * it.
 */
final readonly class ExtensionPresence
{
    public function __construct(
        private InstalledExtensionRepository $installed,
        private CapellPackageRegistry $registry,
    ) {}

    /**
     * True when the package's code is installed and the package registry
     * reports it installed and enabled. A Composer package the registry has
     * never heard of is not an active extension.
     */
    public static function isActive(string $composerName): bool
    {
        return resolve(self::class)->active($composerName);
    }

    public function active(string $composerName): bool
    {
        if (! $this->registry->hasPackage($composerName)) {
            return false;
        }

        $package = $this->registry->getPackage($composerName);

        if (! $this->installed->isAvailable($composerName, $package->path)) {
            return false;
        }

        return $this->registry->isPackageInstalled($composerName)
            && $this->registry->isPackageEnabled($composerName);
    }
}
