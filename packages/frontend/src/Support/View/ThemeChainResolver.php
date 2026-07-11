<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\View;

use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\Theme;
use Capell\Core\Support\Manifest\CapellManifestData;
use Capell\Core\Support\Manifest\ThemeManifestKey;
use Capell\Core\Support\PackageRegistry\CapellPackageRegistry;
use Capell\Frontend\Exceptions\ThemeChainException;
use Composer\InstalledVersions;
use OutOfBoundsException;

final class ThemeChainResolver
{
    private readonly string $cachePath;

    public function __construct(
        private readonly CapellPackageRegistry $registry,
        ?string $cachePath = null,
    ) {
        $this->cachePath = $cachePath ?? base_path('bootstrap/cache/capell-theme-chain.php');
    }

    /** @return list<string> Absolute view directory paths, most-specific first. */
    public function resolve(Theme $theme): array
    {
        $key = $theme->key;

        if (file_exists($this->cachePath)) {
            /** @var array<string, list<string>> $cache */
            $cache = require $this->cachePath;
            if (isset($cache[$key])) {
                return $cache[$key];
            }
        }

        return $this->resolveFromRegistry($key);
    }

    /** @return list<string> */
    private function resolveFromRegistry(string $themeKey): array
    {
        $startingPackage = $this->findPackageForKey($themeKey);

        if (! $startingPackage instanceof CapellManifestData) {
            return [];
        }

        return $this->walkChain($startingPackage);
    }

    private function findPackageForKey(string $themeKey): ?CapellManifestData
    {
        foreach ($this->registry->all() as $manifest) {
            if ($manifest->kind !== 'theme') {
                continue;
            }

            if (ThemeManifestKey::resolve($manifest) === $themeKey) {
                return $manifest;
            }
        }

        return null;
    }

    /** @return list<string> */
    private function walkChain(CapellManifestData $manifest, array $visitedPackages = []): array
    {
        if (in_array($manifest->name, $visitedPackages, true)) {
            throw ThemeChainException::cycle($manifest->name);
        }

        $visitedPackages[] = $manifest->name;
        $paths = [$this->resolveViewPath($manifest->name)];

        if ($manifest->extends === null) {
            return $paths;
        }

        // `extends` names the parent by its theme key (e.g. "default"), the
        // same key space the starting theme is resolved through above — not by
        // composer package name. Resolve it by key so a child theme can extend
        // the foundation theme (package "capell-app/foundation-theme", key
        // "default") without hard-coding the package name in every manifest.
        $parent = $this->findPackageForKey($manifest->extends);

        if (! $parent instanceof CapellManifestData) {
            throw ThemeChainException::missingExtends($manifest->name, $manifest->extends);
        }

        return array_merge($paths, $this->walkChain($parent, $visitedPackages));
    }

    private function resolveViewPath(string $packageName): string
    {
        try {
            $installPath = InstalledVersions::getInstallPath($packageName);
        } catch (OutOfBoundsException) {
            $installPath = null;
        }

        // Fall back to CapellCore-registered package path for local/dev packages
        // that are not registered with Composer InstalledVersions.
        if ($installPath === null && CapellCore::hasPackage($packageName)) {
            $installPath = CapellCore::getPackage($packageName)->path;
        }

        if ($installPath === null) {
            return '';
        }

        return rtrim($installPath, '/') . '/resources/views';
    }
}
