<?php

declare(strict_types=1);

namespace Capell\Core\Support\PackageRegistry;

use Capell\Core\Enums\RuntimeContextEnum;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Support\Manifest\CapellManifestData;
use Capell\Core\Support\Packages\TrustedCorePackages;
use Illuminate\Contracts\Foundation\Application;

final class CapellPackageLoader
{
    public function __construct(
        private readonly Application $app,
        private readonly CapellPackageRegistry $registry,
        private readonly RuntimeContextResolver $contextResolver,
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
        $context = $this->contextResolver->resolve();
        $providers = [];

        foreach ($this->registry->all() as $manifest) {
            foreach ($this->resolveProviders($manifest, $context) as $provider) {
                if (class_exists($provider)) {
                    $providers[] = $provider;
                }
            }
        }

        return $providers;
    }

    /** @return list<string> */
    private function resolveProviders(CapellManifestData $manifest, RuntimeContextEnum $context): array
    {
        $manifestProviders = $manifest->providers->toArray();

        $providers = array_merge(
            $manifestProviders['metadata'] ?? [],
            $manifestProviders['install'] ?? [],
        );

        if ($context === RuntimeContextEnum::Auth) {
            return $this->resolveAuthProviders($manifest, $manifestProviders);
        }

        if (! $this->shouldLoadRuntimeProviders($manifest)) {
            return array_values(array_unique($providers));
        }

        $providers = array_merge(
            $providers,
            $manifestProviders['runtime'] ?? [],
        );

        if (in_array($context, [RuntimeContextEnum::Admin, RuntimeContextEnum::Console], strict: true)) {
            $providers = array_merge($providers, $manifestProviders['admin'] ?? []);
        }

        if ($context === RuntimeContextEnum::Frontend) {
            $providers = array_merge($providers, $manifestProviders['frontend'] ?? []);
        }

        return array_values(array_unique($providers));
    }

    private function shouldLoadRuntimeProviders(CapellManifestData $manifest): bool
    {
        if (TrustedCorePackages::contains($manifest->name)) {
            return true;
        }

        return CapellCore::isPackageEnabled($manifest->name);
    }

    /**
     * @param  array<string, list<class-string>>  $manifestProviders
     * @return list<class-string>
     */
    private function resolveAuthProviders(CapellManifestData $manifest, array $manifestProviders): array
    {
        $providers = $manifestProviders['metadata'] ?? [];

        if (TrustedCorePackages::contains($manifest->name)) {
            return array_values(array_unique(array_merge(
                $providers,
                $manifestProviders['runtime'] ?? [],
                $manifestProviders['auth'] ?? [],
            )));
        }

        $authProviders = $manifestProviders['auth'] ?? [];

        if ($authProviders === [] || ! $this->shouldLoadRuntimeProviders($manifest)) {
            return array_values(array_unique($providers));
        }

        return array_values(array_unique(array_merge($providers, $authProviders)));
    }
}
