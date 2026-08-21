<?php

declare(strict_types=1);

namespace Capell\Core\Actions;

use Capell\Core\Actions\Runtime\BuildRuntimeRoleProviderManifestsAction;
use Capell\Core\Support\Manifest\CapellManifestData;
use Capell\Core\Support\Manifest\ManifestLoader;
use Capell\Core\Support\Manifest\ThemeManifestKey;
use Capell\Core\Support\PackageRegistry\CapellPackageRegistry;
use Capell\Core\ThemeStudio\Discovery\LocalAppThemeDefinitionRepository;
use Composer\InstalledVersions;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Filesystem\Filesystem;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use OutOfBoundsException;

/**
 * @method static void run(array<string, CapellManifestData>|null $manifests = null)
 */
class BuildPackageCacheAction
{
    use AsFake;
    use AsObject;

    public function __construct(
        private readonly Application $application,
        private readonly Filesystem $files,
        private readonly ManifestLoader $manifestLoader,
        private readonly LocalAppThemeDefinitionRepository $localAppThemes,
        private readonly BuildRuntimeRoleProviderManifestsAction $buildRuntimeRoleProviderManifests,
    ) {}

    /** @param array<string, CapellManifestData>|null $manifests */
    public function handle(?array $manifests = null): void
    {
        $manifests ??= $this->manifestLoader->discover();

        $this->writePackagesCache($manifests);
        $this->writeThemeChainCache($manifests);
        $this->localAppThemes->writeCache();
        $this->buildRuntimeRoleProviderManifests->handle();
    }

    /** @param array<string, CapellManifestData> $manifests */
    private function writePackagesCache(array $manifests): void
    {
        $exportable = array_map(
            fn (CapellManifestData $manifest): array => $manifest->toArray(),
            $manifests,
        );

        $this->files->replace(
            $this->application->bootstrapPath('cache/capell-package-manifests.php'),
            '<?php return ' . var_export($exportable, return: true) . ';' . PHP_EOL,
        );
    }

    /** @param array<string, CapellManifestData> $manifests */
    private function writeThemeChainCache(array $manifests): void
    {
        $registry = new CapellPackageRegistry;
        $registry->fill($manifests);

        $chain = [];

        foreach ($manifests as $manifest) {
            if ($manifest->kind !== 'theme') {
                continue;
            }

            $chain[ThemeManifestKey::resolve($manifest)] = $this->walkChain($manifest, $registry);
        }

        $this->files->replace(
            $this->application->bootstrapPath('cache/capell-theme-chain.php'),
            '<?php return ' . var_export($chain, return: true) . ';' . PHP_EOL,
        );
    }

    /**
     * @param  list<string>  $visitedPackages
     * @return list<string>
     */
    private function walkChain(
        CapellManifestData $manifest,
        CapellPackageRegistry $registry,
        array $visitedPackages = [],
    ): array {
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
}
