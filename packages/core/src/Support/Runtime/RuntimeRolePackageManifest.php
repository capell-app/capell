<?php

declare(strict_types=1);

namespace Capell\Core\Support\Runtime;

use Capell\Core\Enums\RuntimeRole;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\PackageManifest;
use Override;
use Throwable;

final class RuntimeRolePackageManifest extends PackageManifest
{
    private readonly string $runtimeManifestPath;

    public function __construct(
        Filesystem $files,
        string $basePath,
        string $manifestPath,
        private readonly string $sourceManifestPath,
        private readonly RuntimeRole $role,
        private readonly RuntimeRoleProviderPolicy $policy,
    ) {
        parent::__construct($files, $basePath, $manifestPath);
        $this->runtimeManifestPath = $manifestPath;
    }

    #[Override]
    public function build(): void
    {
        $sourceManifest = new PackageManifest(
            $this->files,
            $this->basePath,
            $this->sourceManifestPath,
        );
        $sourceManifest->build();
        $this->invalidateOpcache($this->sourceManifestPath);

        $this->manifest = $this->filteredSourceManifest();
        $this->persistRuntimeManifest($this->manifest);
    }

    /** @return array<string, mixed> */
    #[Override]
    protected function getManifest(): array
    {
        if (is_array($this->manifest)) {
            return $this->manifest;
        }

        if ($this->isFresh($this->runtimeManifestPath)) {
            $manifest = $this->files->getRequire($this->runtimeManifestPath);

            if (is_array($manifest)) {
                return $this->manifest = $this->filterManifest($manifest);
            }
        }

        if (! $this->isFresh($this->sourceManifestPath)) {
            try {
                new PackageManifest(
                    $this->files,
                    $this->basePath,
                    $this->sourceManifestPath,
                )->build();
                $this->invalidateOpcache($this->sourceManifestPath);
            } catch (Throwable $throwable) {
                // A read-only bootstrap/cache can still boot from an existing source manifest.
                throw_unless(is_file($this->sourceManifestPath), $throwable);
            }
        }

        $manifest = $this->filteredSourceManifest();
        $this->persistRuntimeManifest($manifest);

        return $this->manifest = $manifest;
    }

    /**
     * A generated manifest is only trusted while it is at least as new as the
     * Composer install set it was derived from. Composer rewrites
     * vendor/composer/installed.json on every install or removal, so a cache
     * written before that (for example over a dev-installed tree that has since
     * been installed with --no-dev) can name providers that no longer exist.
     * Two stat calls per boot; the manifest is only read when it is current.
     */
    private function isFresh(string $path): bool
    {
        if (! is_file($path)) {
            return false;
        }

        $installed = $this->basePath . '/vendor/composer/installed.json';

        if (! is_file($installed)) {
            return true;
        }

        clearstatcache(false, $path);
        clearstatcache(false, $installed);

        return filemtime($path) >= filemtime($installed);
    }

    /** @param array<string, mixed> $manifest */
    private function persistRuntimeManifest(array $manifest): void
    {
        // An empty manifest may come from a half-written installed.json; never trust it for later boots.
        if ($manifest === []) {
            return;
        }

        try {
            $this->files->ensureDirectoryExists(dirname($this->runtimeManifestPath));
            $this->files->replace(
                $this->runtimeManifestPath,
                '<?php return ' . var_export($manifest, return: true) . ';' . PHP_EOL,
            );
            $this->invalidateOpcache($this->runtimeManifestPath);
        } catch (Throwable) {
            // A read-only bootstrap/cache must still boot from the in-memory manifest.
        }
    }

    /** @return array<string, mixed> */
    private function filteredSourceManifest(): array
    {
        $source = is_file($this->sourceManifestPath)
            ? $this->files->getRequire($this->sourceManifestPath)
            : [];

        if (! is_array($source)) {
            return [];
        }

        return $this->filterManifest($source);
    }

    /**
     * @param  array<string, mixed>  $source
     * @return array<string, mixed>
     */
    private function filterManifest(array $source): array
    {
        $manifest = [];

        foreach ($source as $package => $configuration) {
            if (! is_string($package)) {
                continue;
            }

            if (! is_array($configuration)) {
                continue;
            }

            $filtered = $this->policy->filterLaravelPackage($package, $configuration, $this->role);

            if (is_array($filtered)) {
                $manifest[$package] = $filtered;
            }
        }

        return $manifest;
    }

    private function invalidateOpcache(string $path): void
    {
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($path, true);
        }
    }
}
