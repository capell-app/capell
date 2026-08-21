<?php

declare(strict_types=1);

namespace Capell\Core\Support\Runtime;

use Capell\Core\Data\Runtime\RuntimeRoleSelectionData;
use Capell\Core\Enums\RuntimeRole;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Foundation\Bootstrap\LoadEnvironmentVariables;
use Illuminate\Foundation\Bootstrap\RegisterProviders;
use Illuminate\Foundation\PackageManifest;
use Illuminate\Support\Env;
use ReflectionProperty;

final class RuntimeRoleBootstrap
{
    public static function configure(Application $application): void
    {
        $application->afterBootstrapping(
            LoadEnvironmentVariables::class,
            static function (Application $application): void {
                self::configureAfterEnvironment($application);
            },
        );
        $application->afterBootstrapping(
            LoadConfiguration::class,
            static function (Application $application): void {
                self::configureAfterConfiguration($application);
            },
        );
    }

    private static function configureAfterEnvironment(Application $application): void
    {
        $selection = RuntimeRoleSelectionData::fromConfiguredValue(
            Env::get('CAPELL_RUNTIME_ROLE', RuntimeRole::Combined->value),
        );
        $resolver = new RuntimeRoleResolver($selection);
        $policy = new RuntimeRoleProviderPolicy;
        $paths = new RuntimeRoleCachePaths($application);

        $application->instance(RuntimeRoleSelectionData::class, $selection);
        $application->instance(RuntimeRoleResolver::class, $resolver);
        $application->instance(RuntimeRoleProviderPolicy::class, $policy);
        $application->instance(RuntimeRoleCachePaths::class, $paths);

        (new Filesystem)->ensureDirectoryExists(dirname($paths->services($selection->role)));
        self::configureRoleCachePaths($paths, $selection->role);
        self::configureLaravelPackageManifest($application, $paths, $selection->role, $policy);
    }

    private static function configureAfterConfiguration(Application $application): void
    {
        $resolver = $application->make(RuntimeRoleResolver::class);

        self::configureApplicationProviders(
            $application,
            $application->make(RuntimeRoleCachePaths::class),
            $resolver->role(),
            $application->make(RuntimeRoleProviderPolicy::class),
        );
    }

    private static function configureRoleCachePaths(RuntimeRoleCachePaths $paths, RuntimeRole $role): void
    {
        self::setRoleCachePath('APP_CONFIG_CACHE', $paths->config($role));
        self::setRoleCachePath('APP_PACKAGES_CACHE', $paths->packages($role));
        self::setRoleCachePath('APP_SERVICES_CACHE', $paths->services($role));
        self::setRoleCachePath('APP_ROUTES_CACHE', $paths->routes($role));
        self::setRoleCachePath('APP_EVENTS_CACHE', $paths->events($role));
    }

    private static function configureLaravelPackageManifest(
        Application $application,
        RuntimeRoleCachePaths $paths,
        RuntimeRole $role,
        RuntimeRoleProviderPolicy $policy,
    ): void {
        $application->singleton(PackageManifest::class, static fn (): RuntimeRolePackageManifest => new RuntimeRolePackageManifest(
            files: new Filesystem,
            basePath: $application->basePath(),
            manifestPath: $paths->packages($role),
            sourceManifestPath: $application->bootstrapPath('cache/packages.php'),
            role: $role,
            policy: $policy,
        ));
    }

    private static function configureApplicationProviders(
        Application $application,
        RuntimeRoleCachePaths $paths,
        RuntimeRole $role,
        RuntimeRoleProviderPolicy $policy,
    ): void {
        $additionalProviders = self::additionalProviders();
        $configuredProviders = $application->make(Repository::class)->get('app.providers');

        if (is_array($configuredProviders)) {
            $application->make(Repository::class)->set(
                'app.providers',
                $policy->filterProviders(
                    array_values(array_filter($configuredProviders, is_string(...))),
                    $role,
                ),
            );
        }

        $generatedProviderManifest = $paths->providers($role);
        $bootstrapProviderManifest = $application->bootstrapPath('providers.php');
        $providers = is_file($generatedProviderManifest)
            ? self::providersFrom($generatedProviderManifest)
            : self::providersFrom($bootstrapProviderManifest);

        RegisterProviders::flushState();

        RegisterProviders::merge(
            $policy->filterProviders([...$additionalProviders, ...$providers], $role),
            dirname(__DIR__, 3) . '/resources/runtime/empty-providers.php',
        );
    }

    /** @return list<string> */
    private static function additionalProviders(): array
    {
        $merge = new ReflectionProperty(RegisterProviders::class, 'merge')->getValue();

        return is_array($merge)
            ? array_values(array_filter($merge, is_string(...)))
            : [];
    }

    /** @return list<string> */
    private static function providersFrom(string $path): array
    {
        if (! is_file($path)) {
            return [];
        }

        $providers = require $path;

        return is_array($providers)
            ? array_values(array_filter($providers, is_string(...)))
            : [];
    }

    private static function setRoleCachePath(string $key, string $path): void
    {
        Env::getRepository()->set($key, $path);
    }
}
