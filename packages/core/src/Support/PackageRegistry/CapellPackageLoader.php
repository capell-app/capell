<?php

declare(strict_types=1);

namespace Capell\Core\Support\PackageRegistry;

use Capell\Core\Facades\CapellCore;
use Capell\Core\Support\Manifest\CapellManifestData;
use Capell\Core\Support\Packages\TrustedCorePackages;
use Illuminate\Contracts\Foundation\Application;

final class CapellPackageLoader
{
    public function __construct(
        private readonly Application $app,
        private readonly CapellPackageRegistry $registry,
    ) {}

    public function loadProviders(): void
    {
        foreach ($this->collectProviders() as $provider) {
            $this->app->register($provider);
        }
    }

    /** @return list<string> */
    public function collectProviders(): array
    {
        $providers = [];

        foreach ($this->registry->all() as $manifest) {
            foreach ($this->resolveProviders($manifest) as $provider) {
                if (class_exists($provider)) {
                    $providers[] = $provider;
                }
            }
        }

        return $providers;
    }

    /** @return list<string> */
    private function resolveProviders(CapellManifestData $manifest): array
    {
        $manifestProviders = $manifest->providers->toArray();

        $providers = array_merge(
            $manifestProviders['metadata'] ?? [],
            $manifestProviders['install'] ?? [],
        );

        if (! $this->shouldLoadRuntimeProviders($manifest)) {
            return array_values(array_unique($providers));
        }

        $providers = array_merge(
            $providers,
            $manifestProviders['runtime'] ?? [],
            $manifestProviders['admin'] ?? [],
            $manifestProviders['frontend'] ?? [],
            $manifestProviders['auth'] ?? [],
        );

        return array_values(array_unique($providers));
    }

    private function shouldLoadRuntimeProviders(CapellManifestData $manifest): bool
    {
        if (TrustedCorePackages::contains($manifest->name)) {
            return true;
        }

        return CapellCore::isPackageEnabled($manifest->name);
    }
}
