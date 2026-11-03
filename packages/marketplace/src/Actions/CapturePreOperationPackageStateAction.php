<?php

declare(strict_types=1);

namespace Capell\Marketplace\Actions;

use Capell\Core\Actions\Extensions\HashExtensionContentsAction;
use Capell\Core\Support\Packages\TrustedCorePackages;
use Capell\Marketplace\Data\PreOperationPackageStateData;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use Throwable;

/**
 * Record vendor/composer/installed.json and the content hash of every installed
 * non-Core extension before an operation changes anything. Only extensions
 * (packages with a capell.json) are hashed, which bounds the cost; the rollback
 * then compares just the packages the operation changed.
 *
 * @method static PreOperationPackageStateData run(?string $vendorPath = null)
 */
final class CapturePreOperationPackageStateAction
{
    use AsFake;
    use AsObject;

    /** @return array<string, array<string, mixed>>|null */
    public static function installedPackages(string $vendorPath): ?array
    {
        $file = $vendorPath . '/composer/installed.json';
        $contents = is_file($file) ? file_get_contents($file) : false;
        if ($contents === false) {
            return null;
        }

        try {
            $installed = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        $entries = is_array($installed) ? ($installed['packages'] ?? $installed) : null;
        if (! is_array($entries)) {
            return null;
        }

        $packages = [];
        foreach ($entries as $entry) {
            if (is_array($entry) && is_string($entry['name'] ?? null)) {
                /** @var array<string, mixed> $entry */
                $packages[$entry['name']] = $entry;
            }
        }

        return $packages;
    }

    /** @param array<string, mixed> $package */
    public static function installPath(string $vendorPath, array $package): ?string
    {
        $installPath = $package['install-path'] ?? null;
        if (! is_string($installPath)) {
            return null;
        }

        $path = realpath($vendorPath . '/composer/' . $installPath);

        return $path === false ? null : $path;
    }

    /** @param array<string, mixed> $package */
    public static function fingerprint(array $package): string
    {
        unset($package['install-path']);

        return hash('sha256', json_encode($package, JSON_THROW_ON_ERROR));
    }

    public function handle(?string $vendorPath = null): PreOperationPackageStateData
    {
        $vendorPath ??= base_path('vendor');
        $packages = self::installedPackages($vendorPath);
        if ($packages === null) {
            return new PreOperationPackageStateData(null, []);
        }

        $fingerprints = [];
        $hashes = [];
        foreach ($packages as $name => $package) {
            $fingerprints[$name] = self::fingerprint($package);
            if (TrustedCorePackages::contains($name)) {
                continue;
            }

            $path = self::installPath($vendorPath, $package);
            if ($path === null) {
                continue;
            }

            if (! is_file($path . '/capell.json')) {
                continue;
            }

            try {
                $hashes[$name] = HashExtensionContentsAction::run($path);
            } catch (Throwable) {
                // An unhashable extension has no proof to compare against, so a
                // rollback cannot verify it and keeps it quarantined.
            }
        }

        return new PreOperationPackageStateData($fingerprints, $hashes);
    }
}
