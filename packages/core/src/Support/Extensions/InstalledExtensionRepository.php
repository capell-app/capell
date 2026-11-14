<?php

declare(strict_types=1);

namespace Capell\Core\Support\Extensions;

use Capell\Core\Support\Filesystem\AbsolutePath;
use Capell\Core\Support\Json\JsonCodec;
use Composer\InstalledVersions;
use Throwable;

final class InstalledExtensionRepository
{
    public function vendorDirectory(): string
    {
        $contents = is_file(base_path('composer.json')) ? file_get_contents(base_path('composer.json')) : false;
        $root = $contents !== false ? json_decode($contents, true) : null;
        $configured = getenv('COMPOSER_VENDOR_DIR');
        if (! is_string($configured) || $configured === '') {
            $configured = is_array($root) ? ($root['config']['vendor-dir'] ?? 'vendor') : 'vendor';
        }

        if (! is_string($configured) || $configured === '') {
            $configured = 'vendor';
        }

        return AbsolutePath::is($configured) ? $configured : base_path($configured);
    }

    public function isAvailable(string $composerName, ?string $path = null): bool
    {
        if ($this->composerPackageInstalled($composerName)) {
            return true;
        }

        return $this->pathPackageMatches($composerName, $path);
    }

    public function version(string $composerName): ?string
    {
        try {
            return InstalledVersions::isInstalled($composerName)
                ? InstalledVersions::getPrettyVersion($composerName)
                : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function composerPackageInstalled(string $composerName): bool
    {
        try {
            return InstalledVersions::isInstalled($composerName);
        } catch (Throwable) {
            return false;
        }
    }

    private function pathPackageMatches(string $composerName, ?string $path): bool
    {
        if (! is_string($path) || $path === '') {
            return false;
        }

        $composerJsonPath = rtrim($path, '/') . '/composer.json';

        if (! is_file($composerJsonPath)) {
            return false;
        }

        try {
            $contents = file_get_contents($composerJsonPath);

            if ($contents === false) {
                return false;
            }

            $decoded = JsonCodec::decodeArray($contents);

            return ($decoded['name'] ?? null) === $composerName;
        } catch (Throwable) {
            return false;
        }
    }
}
