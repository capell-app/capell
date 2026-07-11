<?php

declare(strict_types=1);

namespace Capell\Core\Console\Commands;

use Capell\Core\Support\Manifest\CapellManifestData;
use Capell\Core\Support\Manifest\ManifestLoader;
use Capell\Core\Support\Manifest\ManifestValidator;
use Capell\Core\Support\Manifest\ThemeManifestKey;
use Capell\Core\Support\PackageRegistry\CapellPackageRegistry;
use Capell\Core\ThemeStudio\Discovery\LocalAppThemeDefinitionRepository;
use Composer\InstalledVersions;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use OutOfBoundsException;

final class PackageCacheCommand extends Command
{
    protected $description = 'Create a cache file for faster Capell package manifest loading';

    protected $signature = 'capell:package-cache';

    public function handle(): int
    {
        $loader = new ManifestLoader(new ManifestValidator);
        $manifests = $loader->discover();

        $this->writePackagesCache($manifests);
        $this->writeThemeChainCache($manifests);
        resolve(LocalAppThemeDefinitionRepository::class)->writeCache();

        $this->components->info('Capell package manifest cache created successfully.');

        return self::SUCCESS;
    }

    /** @param array<string, CapellManifestData> $manifests */
    private function writePackagesCache(array $manifests): void
    {
        $cachePath = $this->laravel->bootstrapPath('cache/capell-package-manifests.php');

        $exportable = array_map(
            fn (CapellManifestData $manifest): array => $manifest->toArray(),
            $manifests,
        );

        $this->files()->replace(
            $cachePath,
            '<?php return ' . var_export($exportable, return: true) . ';' . PHP_EOL,
        );
    }

    /** @param array<string, CapellManifestData> $manifests */
    private function writeThemeChainCache(array $manifests): void
    {
        $cachePath = $this->laravel->bootstrapPath('cache/capell-theme-chain.php');

        $registry = new CapellPackageRegistry;
        $registry->fill($manifests);

        $chain = [];

        foreach ($manifests as $manifest) {
            if ($manifest->kind !== 'theme') {
                continue;
            }

            $key = ThemeManifestKey::resolve($manifest);
            $chain[$key] = $this->walkChain($manifest, $registry);
        }

        $this->files()->replace(
            $cachePath,
            '<?php return ' . var_export($chain, return: true) . ';' . PHP_EOL,
        );
    }

    /**
     * @param  list<string>  $visitedPackages
     * @return list<string>
     */
    private function walkChain(CapellManifestData $manifest, CapellPackageRegistry $registry, array $visitedPackages = []): array
    {
        if (in_array($manifest->name, $visitedPackages, true)) {
            throw new OutOfBoundsException(sprintf('Theme inheritance cycle detected for [%s].', $manifest->name));
        }

        $visitedPackages[] = $manifest->name;

        $viewPath = $this->resolveViewPath($manifest);
        $paths = $viewPath !== '' ? [$viewPath] : [];

        if ($manifest->extends === null) {
            return $paths;
        }

        $parent = $this->resolveParentManifest($manifest->extends, $registry);

        if (! $parent instanceof CapellManifestData) {
            throw new OutOfBoundsException(sprintf(
                'Theme package [%s] extends missing package [%s].',
                $manifest->name,
                $manifest->extends,
            ));
        }

        return array_merge($paths, $this->walkChain($parent, $registry, $visitedPackages));
    }

    private function resolveParentManifest(string $extends, CapellPackageRegistry $registry): ?CapellManifestData
    {
        $parent = $registry->get($extends);

        if ($parent instanceof CapellManifestData) {
            return $parent;
        }

        foreach ($registry->all() as $candidate) {
            if ($candidate->kind === 'theme' && $candidate->themeKey === $extends) {
                return $candidate;
            }
        }

        return null;
    }

    private function resolveViewPath(CapellManifestData $manifest): string
    {
        try {
            $installPath = InstalledVersions::getInstallPath($manifest->name);
        } catch (OutOfBoundsException) {
            $installPath = null;
        }

        $installPath ??= $manifest->installPath;

        if ($installPath === null) {
            return '';
        }

        return rtrim($installPath, '/') . '/resources/views';
    }

    private function files(): Filesystem
    {
        return new Filesystem;
    }
}
