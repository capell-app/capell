<?php

declare(strict_types=1);

namespace Capell\Core\Support\Runtime;

use Capell\Core\Enums\RuntimeRole;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\PackageManifest;
use Override;

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

        $this->manifest = $this->filteredSourceManifest();
    }

    /** @return array<string, mixed> */
    #[Override]
    protected function getManifest(): array
    {
        if (is_array($this->manifest)) {
            return $this->manifest;
        }

        if (is_file($this->runtimeManifestPath)) {
            $manifest = $this->files->getRequire($this->runtimeManifestPath);

            if (is_array($manifest)) {
                return $this->manifest = $this->filterManifest($manifest);
            }
        }

        if (! is_file($this->sourceManifestPath)) {
            new PackageManifest(
                $this->files,
                $this->basePath,
                $this->sourceManifestPath,
            )->build();
        }

        return $this->manifest = $this->filteredSourceManifest();
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
}
