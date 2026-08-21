<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Runtime;

use Capell\Core\Enums\RuntimeRole;
use Capell\Core\Support\Runtime\RuntimeRoleCachePaths;
use Capell\Core\Support\Runtime\RuntimeRoleProviderPolicy;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\PackageManifest;
use Illuminate\Support\ServiceProvider;
use Lorisleiva\Actions\Concerns\AsObject;
use RuntimeException;

final class BuildRuntimeRoleProviderManifestsAction
{
    use AsObject;

    public function __construct(
        private readonly Application $application,
        private readonly Filesystem $files,
        private readonly RuntimeRoleCachePaths $paths,
        private readonly RuntimeRoleProviderPolicy $policy,
    ) {}

    public function handle(): void
    {
        $sourcePackagesPath = $this->application->bootstrapPath('cache/packages.php');

        if (! is_file($sourcePackagesPath)) {
            new PackageManifest(
                $this->files,
                $this->application->basePath(),
                $sourcePackagesPath,
            )->build();
        }

        $sourcePackages = $this->requireArray($sourcePackagesPath);
        $bootstrapProvidersPath = $this->application->bootstrapPath('providers.php');
        $bootstrapProviders = $this->providerList($bootstrapProvidersPath);
        $configuredProviders = $this->configuredProviders($bootstrapProviders);

        $this->files->ensureDirectoryExists($this->paths->directory());

        foreach (RuntimeRole::deploymentRoles() as $role) {
            $this->files->ensureDirectoryExists(dirname($this->paths->packages($role)));

            $packages = $this->filterPackages($sourcePackages, $role);
            $providers = $this->policy->filterProviders($bootstrapProviders, $role);
            $allProviders = $this->laravelProviderList($configuredProviders, $packages, $role);

            $this->writePhpArray($this->paths->packages($role), $packages);
            $this->writePhpArray($this->paths->providers($role), $providers);
            $this->writePhpArray($this->paths->services($role), $this->compileServices($allProviders));
        }

        $this->writePhpArray($this->paths->metadata(), [
            'schema_version' => 1,
            'source_packages_sha256' => hash_file('sha256', $sourcePackagesPath),
            'bootstrap_providers_sha256' => is_file($bootstrapProvidersPath)
                ? hash_file('sha256', $bootstrapProvidersPath)
                : null,
            'roles' => array_map(
                static fn (RuntimeRole $role): string => $role->value,
                RuntimeRole::deploymentRoles(),
            ),
        ]);
    }

    /**
     * @param  array<string, mixed>  $sourcePackages
     * @return array<string, mixed>
     */
    private function filterPackages(array $sourcePackages, RuntimeRole $role): array
    {
        $packages = [];

        foreach ($sourcePackages as $package => $configuration) {
            if (! is_string($package)) {
                continue;
            }

            if (! is_array($configuration)) {
                continue;
            }

            $filtered = $this->policy->filterLaravelPackage($package, $configuration, $role);

            if (is_array($filtered)) {
                $packages[$package] = $filtered;
            }
        }

        return $packages;
    }

    /**
     * @param  list<string>  $bootstrapProviders
     * @return list<string>
     */
    private function configuredProviders(array $bootstrapProviders): array
    {
        $configured = config('app.providers');
        $configured = is_array($configured)
            ? array_values(array_filter($configured, is_string(...)))
            : ServiceProvider::defaultProviders()->toArray();

        return array_values(array_unique([
            ...$configured,
            ...$bootstrapProviders,
        ]));
    }

    /**
     * @param  list<string>  $configuredProviders
     * @param  array<string, mixed>  $packages
     * @return list<string>
     */
    private function laravelProviderList(array $configuredProviders, array $packages, RuntimeRole $role): array
    {
        $configuredProviders = $this->policy->filterProviders($configuredProviders, $role);
        $frameworkProviders = [];
        $applicationProviders = [];

        foreach ($configuredProviders as $provider) {
            if (str_starts_with($provider, 'Illuminate\\')) {
                $frameworkProviders[] = $provider;
            } else {
                $applicationProviders[] = $provider;
            }
        }

        $packageProviders = [];
        foreach ($packages as $configuration) {
            if (! is_array($configuration)) {
                continue;
            }

            if (! is_array($configuration['providers'] ?? null)) {
                continue;
            }

            foreach ($configuration['providers'] as $provider) {
                if (is_string($provider)) {
                    $packageProviders[] = $provider;
                }
            }
        }

        return array_values(array_filter(
            array_unique([
                ...$frameworkProviders,
                ...$packageProviders,
                ...$applicationProviders,
            ]),
            class_exists(...),
        ));
    }

    /**
     * @param  list<string>  $providers
     * @return array{providers: list<string>, eager: list<string>, deferred: array<string, string>, when: array<string, list<string>>}
     */
    private function compileServices(array $providers): array
    {
        $manifest = [
            'providers' => $providers,
            'eager' => [],
            'deferred' => [],
            'when' => [],
        ];

        foreach ($providers as $provider) {
            $instance = new $provider($this->application);

            if (! $instance instanceof ServiceProvider) {
                throw new RuntimeException(sprintf('Runtime role provider [%s] is not a Laravel service provider.', $provider));
            }

            if (! $instance->isDeferred()) {
                $manifest['eager'][] = $provider;

                continue;
            }

            foreach ($instance->provides() as $service) {
                $manifest['deferred'][$service] = $provider;
            }

            $manifest['when'][$provider] = array_values(array_filter(
                $instance->when(),
                is_string(...),
            ));
        }

        return $manifest;
    }

    /** @return array<string, mixed> */
    private function requireArray(string $path): array
    {
        $value = require $path;

        throw_unless(is_array($value), RuntimeException::class, sprintf('Runtime role manifest source [%s] must return an array.', $path));

        return $value;
    }

    /** @return list<string> */
    private function providerList(string $path): array
    {
        if (! is_file($path)) {
            return [];
        }

        return array_values(array_filter($this->requireArray($path), is_string(...)));
    }

    /** @param array<mixed> $value */
    private function writePhpArray(string $path, array $value): void
    {
        $this->files->replace(
            $path,
            '<?php return ' . var_export($value, return: true) . ';' . PHP_EOL,
        );
    }
}
