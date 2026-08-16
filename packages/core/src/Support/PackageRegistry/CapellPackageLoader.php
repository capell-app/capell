<?php

declare(strict_types=1);

namespace Capell\Core\Support\PackageRegistry;

use Capell\Core\Facades\CapellCore;
use Capell\Core\Support\Manifest\CapellManifestData;
use Capell\Core\Support\Packages\TrustedCorePackages;
use Illuminate\Contracts\Foundation\Application;
use Throwable;

final class CapellPackageLoader
{
    public function __construct(
        private readonly Application $app,
        private readonly CapellPackageRegistry $registry,
    ) {}

    public function loadProviders(): void
    {
        foreach ($this->registry->all() as $manifest) {
            $this->loadProvidersForPackage($manifest->name, $this->resolveProviders($manifest));
        }
    }

    /**
     * Register providers attributed to one package and return the providers
     * that registered successfully. Composer metadata needs the same failure
     * handling as manifest providers during the install bootstrap window.
     *
     * @param  list<string>  $providers
     * @return list<string>
     */
    public function loadProvidersForPackage(string $packageName, array $providers): array
    {
        $loadedProviders = [];

        foreach (array_values(array_unique($providers)) as $provider) {
            if (! class_exists($provider)) {
                continue;
            }

            try {
                $this->app->register($provider);
                $loadedProviders[] = $provider;
            } catch (Throwable $throwable) {
                $this->handleProviderFailure($packageName, $provider, $throwable);

                break;
            }
        }

        return $loadedProviders;
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

        if (($_SERVER['CAPELL_INSTALL_CONTEXT'] ?? getenv('CAPELL_INSTALL_CONTEXT')) === 'install') {
            $selected = $_SERVER['CAPELL_INSTALL_PACKAGES'] ?? getenv('CAPELL_INSTALL_PACKAGES');
            if (! is_string($selected)) {
                return false;
            }

            $selectedPackages = array_filter(
                array_map('trim', explode(',', $selected)),
                static fn (string $packageName): bool => $packageName !== '',
            );

            return in_array($manifest->name, $selectedPackages, true);
        }

        return CapellCore::isPackageEnabled($manifest->name);
    }

    private function handleProviderFailure(string $packageName, string $provider, Throwable $throwable): void
    {
        throw_if(TrustedCorePackages::contains($packageName), $throwable);

        CapellCore::markPackageProviderQuarantined(
            name: $packageName,
            provider: $provider,
            reason: $this->providerFailureReason($provider, $throwable),
        );
    }

    private function providerFailureReason(string $provider, Throwable $throwable): string
    {
        return sprintf(
            'Provider [%s] failed during registration with [%s].',
            $provider,
            $throwable::class,
        );
    }
}
