<?php

declare(strict_types=1);

namespace Capell\Core\Support\Diagnostics\Checks;

use Capell\Core\Data\Diagnostics\DoctorCheckResultData;
use Capell\Core\Enums\Diagnostics\DoctorCheckSeverity;
use Capell\Core\Enums\RuntimeRole;
use Capell\Core\Support\Runtime\RuntimeRoleCachePaths;
use Capell\Core\Support\Runtime\RuntimeRolePackageManifest;
use Capell\Core\Support\Runtime\RuntimeRoleProviderPolicy;
use Capell\Core\Support\Runtime\RuntimeRoleResolver;
use Composer\InstalledVersions;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\PackageManifest;
use Throwable;

final class RuntimeRoleCheck extends AbstractDoctorCheck
{
    public function __construct(
        private readonly Application $application,
        private readonly RuntimeRoleResolver $resolver,
        private readonly RuntimeRoleCachePaths $paths,
        private readonly RuntimeRoleProviderPolicy $policy,
    ) {}

    protected function id(): string
    {
        return 'core.runtime.role';
    }

    protected function severity(): DoctorCheckSeverity
    {
        return DoctorCheckSeverity::Critical;
    }

    protected function run(bool $installSummary): DoctorCheckResultData
    {
        $selection = $this->resolver->selection();
        $role = $selection->role;
        $requiredPaths = [
            $this->paths->packages($role),
            $this->paths->providers($role),
            $this->paths->services($role),
            $this->paths->metadata(),
        ];
        $evidence = [
            'configured_value' => $selection->configuredValue,
            'role' => $role->value,
            'valid' => $selection->valid,
            'required_paths' => $requiredPaths,
        ];

        if (! $selection->valid) {
            return $this->failed(
                $evidence,
                sprintf('CAPELL_RUNTIME_ROLE [%s] is invalid; Capell fell back to the combined role.', $selection->configuredValue),
                'Set CAPELL_RUNTIME_ROLE to combined, public, or authoring, then restart every PHP and Octane process.',
            );
        }

        $missingPaths = array_values(array_filter($requiredPaths, static fn (string $path): bool => ! is_file($path)));
        $evidence['missing_paths'] = $missingPaths;

        if ($missingPaths !== []) {
            if ($role === RuntimeRole::Combined && count($missingPaths) === count($requiredPaths)) {
                return new DoctorCheckResultData(
                    label: 'Runtime role provider graph is isolated',
                    passed: true,
                    message: 'The backward-compatible combined runtime is active without generated split-role manifests.',
                    remediation: 'Run php artisan capell:package-cache during deployment before selecting public or authoring.',
                    severity: $this->severity(),
                    evidence: $evidence,
                );
            }

            return $this->failed(
                $evidence,
                sprintf('Generated provider manifests for the [%s] runtime role are incomplete.', $role->value),
                'Run php artisan capell:package-cache in the release artefact, then restart every process for this role.',
            );
        }

        $metadata = $this->readArray($this->paths->metadata());
        $packages = $this->readArray($this->paths->packages($role));
        $providerManifest = $this->readArray($this->paths->providers($role));
        $services = $this->readArray($this->paths->services($role));

        if ($metadata === null || $packages === null || $providerManifest === null || $services === null) {
            return $this->failed(
                $evidence,
                sprintf('Generated provider manifests for the [%s] runtime role are not valid PHP arrays.', $role->value),
                'Rebuild the release artefact with php artisan capell:package-cache.',
            );
        }

        $providers = $this->stringList($providerManifest);

        $errors = $this->sourceHashErrors($metadata);
        $errors = [...$errors, ...$this->cachePathErrors($role)];

        if (! $this->application->bound(PackageManifest::class)
            || ! $this->application->make(PackageManifest::class) instanceof RuntimeRolePackageManifest) {
            $errors[] = 'Laravel is not using the role-aware package manifest.';
        }

        $providerGraph = array_values(array_unique([
            ...$providers,
            ...$this->packageProviders($packages),
            ...$this->stringList(is_array($services['providers'] ?? null) ? $services['providers'] : null),
        ]));
        $loadedProviders = array_keys(array_filter(
            $this->application->getLoadedProviders(),
            static fn (bool $loaded): bool => $loaded,
        ));
        $evidence['provider_count'] = count($providerGraph);
        $evidence['loaded_provider_count'] = count($loadedProviders);

        if ($role === RuntimeRole::Public) {
            $authoringPackages = array_values(array_filter(
                array_keys($packages),
                $this->policy->isAuthoringPackage(...),
            ));
            $authoringProviders = array_values(array_filter(
                [...$providerGraph, ...$this->packageAliases($packages), ...$loadedProviders],
                $this->policy->isAuthoringProvider(...),
            ));
            $evidence['authoring_packages'] = array_values(array_unique($authoringPackages));
            $evidence['authoring_providers'] = array_values(array_unique($authoringProviders));

            if ($authoringPackages !== []) {
                $errors[] = 'The public package manifest contains authoring-only packages.';
            }

            if ($authoringProviders !== []) {
                $errors[] = 'The public provider graph contains authoring-only providers.';
            }
        }

        if ($role === RuntimeRole::Authoring && InstalledVersions::isInstalled('capell-app/frontend')) {
            if (! array_any($providerGraph, $this->policy->isFrontendProvider(...))) {
                $errors[] = 'The authoring provider graph does not contain Frontend, so authenticated previews cannot use the public renderer.';
            }

            if (! array_any($loadedProviders, $this->policy->isFrontendProvider(...))) {
                $errors[] = 'Frontend is not loaded in the authoring process, so authenticated previews cannot use the public renderer.';
            }
        }

        if ($errors !== []) {
            $evidence['errors'] = $errors;

            return $this->failed(
                $evidence,
                sprintf('The [%s] runtime role does not match its generated provider contract.', $role->value),
                'Rebuild role manifests with php artisan capell:package-cache and restart every process for this role. Set CAPELL_RUNTIME_ROLE=combined to roll back without changing code or data.',
            );
        }

        return new DoctorCheckResultData(
            label: 'Runtime role provider graph is isolated',
            passed: true,
            message: sprintf('The immutable [%s] runtime role matches its generated provider and cache manifests.', $role->value),
            severity: $this->severity(),
            evidence: $evidence,
        );
    }

    /** @param array<string, mixed> $evidence */
    private function failed(array $evidence, string $message, string $remediation): DoctorCheckResultData
    {
        return new DoctorCheckResultData(
            label: 'Runtime role provider graph is isolated',
            passed: false,
            message: $message,
            remediation: $remediation,
            severity: $this->severity(),
            evidence: $evidence,
        );
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return list<string>
     */
    private function sourceHashErrors(array $metadata): array
    {
        $errors = [];
        $sourcePackagesPath = $this->application->bootstrapPath('cache/packages.php');
        $bootstrapProvidersPath = $this->application->bootstrapPath('providers.php');
        $expectedRoles = array_map(
            static fn (RuntimeRole $role): string => $role->value,
            RuntimeRole::deploymentRoles(),
        );

        if (($metadata['schema_version'] ?? null) !== 1) {
            $errors[] = 'The generated role manifest schema version is unsupported.';
        }

        if (($metadata['roles'] ?? null) !== $expectedRoles) {
            $errors[] = 'The generated role manifest does not contain every immutable deployment role.';
        }

        if (is_file($sourcePackagesPath)
            && ($metadata['source_packages_sha256'] ?? null) !== hash_file('sha256', $sourcePackagesPath)) {
            $errors[] = 'The generated role manifests do not match bootstrap/cache/packages.php.';
        }

        if (is_file($bootstrapProvidersPath)
            && ($metadata['bootstrap_providers_sha256'] ?? null) !== hash_file('sha256', $bootstrapProvidersPath)) {
            $errors[] = 'The generated role manifests do not match bootstrap/providers.php.';
        }

        return $errors;
    }

    /** @return list<string> */
    private function cachePathErrors(RuntimeRole $role): array
    {
        $expected = [
            'config' => $this->paths->config($role),
            'packages' => $this->paths->packages($role),
            'services' => $this->paths->services($role),
            'routes' => $this->paths->routes($role),
            'events' => $this->paths->events($role),
        ];
        $actual = [
            'config' => $this->application->getCachedConfigPath(),
            'packages' => $this->application->getCachedPackagesPath(),
            'services' => $this->application->getCachedServicesPath(),
            'routes' => $this->application->getCachedRoutesPath(),
            'events' => $this->application->getCachedEventsPath(),
        ];
        $errors = [];

        foreach ($expected as $cache => $path) {
            if ($actual[$cache] !== $path) {
                $errors[] = sprintf('The %s cache path is not isolated for the [%s] role.', $cache, $role->value);
            }
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $packages
     * @return list<string>
     */
    private function packageProviders(array $packages): array
    {
        $providers = [];

        foreach ($packages as $configuration) {
            if (! is_array($configuration)) {
                continue;
            }

            $providers = [...$providers, ...$this->stringList($configuration['providers'] ?? null)];
        }

        return $providers;
    }

    /**
     * @param  array<string, mixed>  $packages
     * @return list<string>
     */
    private function packageAliases(array $packages): array
    {
        $aliases = [];

        foreach ($packages as $configuration) {
            if (! is_array($configuration)) {
                continue;
            }

            $aliases = [...$aliases, ...$this->stringList($configuration['aliases'] ?? null)];
        }

        return $aliases;
    }

    /** @return array<string, mixed>|null */
    private function readArray(string $path): ?array
    {
        try {
            $value = require $path;
        } catch (Throwable) {
            return null;
        }

        return is_array($value) ? $value : null;
    }

    /** @return list<string> */
    private function stringList(mixed $value): array
    {
        return is_array($value)
            ? array_values(array_filter($value, is_string(...)))
            : [];
    }
}
