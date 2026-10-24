<?php

declare(strict_types=1);

namespace Capell\Marketplace\Support;

use Capell\Marketplace\Contracts\MarketplaceInstalledPackageVersionResolver;
use Composer\InstalledVersions;
use Override;
use ReflectionClass;

final class ComposerInstalledPackageVersionResolver implements MarketplaceInstalledPackageVersionResolver
{
    public function __construct(private readonly ?string $vendorPath = null) {}

    #[Override]
    public function prettyVersion(string $composerName): ?string
    {
        // Composer excludes the root project from installed.json. Keep its version
        // available to foundation compatibility checks; it is not a dependency update.
        $root = InstalledVersions::getRootPackage();

        if ($root['name'] === $composerName) {
            return $root['pretty_version'];
        }

        // A queue worker's InstalledVersions snapshot can predate another operation.
        // Read the installed release on every check, under the caller's operation lock.
        // JSON also avoids a stale installed.php entry in the opcode cache.
        $path = ($this->vendorPath !== null ? $this->vendorPath . '/composer' : dirname((string) new ReflectionClass(InstalledVersions::class)->getFileName())) . '/installed.json';

        if (! is_file($path)) {
            return null;
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            return null;
        }

        $installed = json_decode($contents, associative: true, flags: JSON_THROW_ON_ERROR);
        $packages = is_array($installed) ? ($installed['packages'] ?? $installed) : [];

        if (! is_array($packages)) {
            return null;
        }

        foreach ($packages as $package) {
            if (is_array($package) && ($package['name'] ?? null) === $composerName) {
                $version = $package['version'] ?? null;

                return is_string($version) && $version !== '' ? $version : null;
            }
        }

        return null;
    }
}
