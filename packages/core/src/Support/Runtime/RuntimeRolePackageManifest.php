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

    private readonly ComposerInstallIdentity $identity;

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
        $this->identity = new ComposerInstallIdentity($files, $basePath);
    }

    #[Override]
    public function build(): void
    {
        $snapshot = $this->identity->snapshot();

        new PackageManifest(
            $this->files,
            $this->basePath,
            $this->sourceManifestPath,
        )->build();
        $this->invalidateOpcache($this->sourceManifestPath);
        $this->identity->record($this->sourceIdentityPath(), $snapshot);

        $this->manifest = $this->filteredSourceManifest();
        $this->persistRuntimeManifest($this->manifest, $snapshot);
    }

    /** @return array<string, mixed> */
    #[Override]
    protected function getManifest(): array
    {
        if (is_array($this->manifest)) {
            return $this->manifest;
        }

        if (is_file($this->runtimeManifestPath) && $this->identity->matches($this->runtimeIdentityPath())) {
            $manifest = $this->files->getRequire($this->runtimeManifestPath);

            if (is_array($manifest)) {
                return $this->manifest = $this->filterManifest($manifest);
            }
        }

        // Taken before any rebuild so a change made meanwhile is caught on the next boot.
        $snapshot = $this->identity->snapshot();

        if (! is_file($this->sourceManifestPath) || ! $this->identity->matches($this->sourceIdentityPath())) {
            try {
                new PackageManifest(
                    $this->files,
                    $this->basePath,
                    $this->sourceManifestPath,
                )->build();
                $this->invalidateOpcache($this->sourceManifestPath);
                $this->identity->record($this->sourceIdentityPath(), $snapshot);
            } catch (Throwable) {
                // A read-only bootstrap/cache cannot hold the rebuilt source manifest, and the
                // existing one is known to be stale: derive the current manifest in memory.
                return $this->manifest = $this->filterManifest($this->buildInMemory());
            }
        }

        $manifest = $this->filteredSourceManifest();
        $this->persistRuntimeManifest($manifest, $snapshot);

        return $this->manifest = $manifest;
    }

    /** @return array<string, mixed> */
    private function buildInMemory(): array
    {
        // A unique temporary path keeps opcache from serving an earlier manifest.
        $temporary = sys_get_temp_dir() . '/capell-package-manifest-' . bin2hex(random_bytes(8)) . '.php';

        try {
            new PackageManifest($this->files, $this->basePath, $temporary)->build();
            $manifest = $this->files->getRequire($temporary);
        } finally {
            $this->files->delete($temporary);
        }

        return is_array($manifest) ? $manifest : [];
    }

    private function runtimeIdentityPath(): string
    {
        return dirname($this->runtimeManifestPath) . '/install-identity.php';
    }

    private function sourceIdentityPath(): string
    {
        return dirname($this->sourceManifestPath) . '/capell-runtime/source-install-identity.php';
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @param  array{sha256: string, size: int, mtime: int, generated_at: int}|null  $snapshot
     */
    private function persistRuntimeManifest(array $manifest, ?array $snapshot): void
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
            $this->identity->record($this->runtimeIdentityPath(), $snapshot);
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
