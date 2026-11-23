<?php

declare(strict_types=1);

namespace Capell\Core\Support\Runtime;

use Capell\Core\Data\Runtime\RuntimeRoleSelectionData;
use Capell\Core\Enums\RuntimeRole;
use Capell\Core\Exceptions\RuntimeRoleMisconfiguredException;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Foundation\Application as ApplicationContract;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Foundation\Bootstrap\LoadEnvironmentVariables;
use Illuminate\Foundation\Bootstrap\RegisterProviders;
use Illuminate\Foundation\PackageManifest;
use Illuminate\Support\Env;
use ReflectionProperty;
use Symfony\Component\Console\Input\ArgvInput;

final class RuntimeRoleBootstrap
{
    /** Container marker proving the role-aware bootstrap ran for this application. */
    public const string CONFIGURED_MARKER = 'capell.runtime-role.bootstrapped';

    /** Console commands that stay available on a misconfigured node so an operator can inspect it. */
    private const array REPAIR_COMMANDS = ['capell:doctor'];

    /**
     * Refuse to carry on as an unintended combined node: a split role that was configured
     * in the environment but never enforced by the host bootstrap.
     */
    public static function assertConfigured(ApplicationContract $application, RuntimeRoleSelectionData $selection): void
    {
        if (! $selection->valid
            || $selection->role === RuntimeRole::Combined
            || self::isConfigured($application)
            || self::isRepairCommand($application)) {
            return;
        }

        throw RuntimeRoleMisconfiguredException::bootstrapMissing($selection->role);
    }

    public static function isConfigured(Container $container): bool
    {
        return $container->bound(self::CONFIGURED_MARKER);
    }

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

    /**
     * Configure an application factory that resolves environment and configuration
     * without dispatching Laravel's standard bootstrapper events.
     */
    public static function configureResolvedApplication(Application $application): void
    {
        self::configureResolvedEnvironment($application);
        self::configureResolvedConfiguration($application);
    }

    /**
     * Configure an application after its environment has been resolved but before its
     * configuration is loaded.
     */
    public static function configureResolvedEnvironment(Application $application): void
    {
        self::configureAfterEnvironment($application);
    }

    /**
     * Configure an application after its configuration has been loaded.
     */
    public static function configureResolvedConfiguration(
        Application $application,
        bool $includeGeneratedProviders = true,
        bool $includeAdditionalProviders = true,
    ): void {
        self::configureAfterConfiguration($application, $includeGeneratedProviders, $includeAdditionalProviders);
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
        $application->instance(self::CONFIGURED_MARKER, true);

        (new Filesystem)->ensureDirectoryExists(dirname($paths->services($selection->role)));
        self::configureRoleCachePaths($paths, $selection->role);
        self::configureLaravelPackageManifest($application, $paths, $selection->role, $policy);
    }

    private static function configureAfterConfiguration(
        Application $application,
        bool $includeGeneratedProviders = true,
        bool $includeAdditionalProviders = true,
    ): void {
        $resolver = $application->make(RuntimeRoleResolver::class);

        self::configureApplicationProviders(
            $application,
            $application->make(RuntimeRoleCachePaths::class),
            $resolver->role(),
            $application->make(RuntimeRoleProviderPolicy::class),
            $includeGeneratedProviders,
            $includeAdditionalProviders,
        );
    }

    private static function configureRoleCachePaths(RuntimeRoleCachePaths $paths, RuntimeRole $role): void
    {
        self::setRoleCachePath($role, 'APP_CONFIG_CACHE', $paths->config($role));
        self::setRoleCachePath($role, 'APP_PACKAGES_CACHE', $paths->packages($role));
        self::setRoleCachePath($role, 'APP_SERVICES_CACHE', $paths->services($role));
        self::setRoleCachePath($role, 'APP_ROUTES_CACHE', $paths->routes($role));
        self::setRoleCachePath($role, 'APP_EVENTS_CACHE', $paths->events($role));
    }

    private static function configureLaravelPackageManifest(
        Application $application,
        RuntimeRoleCachePaths $paths,
        RuntimeRole $role,
        RuntimeRoleProviderPolicy $policy,
    ): void {
        // Testbench swaps a resolved generic manifest into the container while
        // creating its application. Clear that instance before installing the
        // role-aware singleton so later package discovery cannot bypass it.
        $application->forgetInstance(PackageManifest::class);
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
        bool $includeGeneratedProviders = true,
        bool $includeAdditionalProviders = true,
    ): void {
        $additionalProviders = $includeAdditionalProviders ? self::additionalProviders() : [];
        $configuredProviders = $application->make(Repository::class)->get('app.providers');

        if (is_array($configuredProviders)) {
            $configuredProviders = $policy->filterProviders(
                array_values(array_filter($configuredProviders, is_string(...))),
                $role,
            );
            $configuredProviders = array_values(array_unique($configuredProviders));

            $application->make(Repository::class)->set('app.providers', $configuredProviders);
        } else {
            $configuredProviders = [];
        }

        $generatedProviderManifest = $includeGeneratedProviders ? $paths->providers($role) : null;
        $bootstrapProviderManifest = $application->bootstrapPath('providers.php');
        $providers = is_string($generatedProviderManifest) && is_file($generatedProviderManifest)
            ? self::providersFrom($generatedProviderManifest)
            : self::providersFrom($bootstrapProviderManifest);

        if (! $includeGeneratedProviders) {
            $providers = [];
        }

        RegisterProviders::flushState();
        $providersToMerge = array_values(array_unique($policy->filterProviders(
            [...$additionalProviders, ...$providers],
            $role,
        )));
        $providersToMerge = array_values(array_diff($providersToMerge, $configuredProviders));

        RegisterProviders::merge(
            $providersToMerge,
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

    private static function setRoleCachePath(RuntimeRole $role, string $key, string $path): void
    {
        Env::getRepository()->set($key, $path);

        // The environment repository is immutable: a variable defined by the real process
        // environment silently refuses the write, leaving the node on a shared cache. Combined
        // keeps the documented per-command override (for example APP_CONFIG_CACHE pointing at
        // a missing file); a split role must be isolated, so a different value is refused.
        $effective = Env::get($key);

        if ($role !== RuntimeRole::Combined && $effective !== $path) {
            throw RuntimeRoleMisconfiguredException::cachePathOverridden(
                $role,
                $key,
                is_string($effective) ? $effective : get_debug_type($effective),
                $path,
            );
        }
    }

    private static function isRepairCommand(ApplicationContract $application): bool
    {
        if (! $application->runningInConsole()) {
            return false;
        }

        return in_array(new ArgvInput()->getFirstArgument(), self::REPAIR_COMMANDS, true);
    }
}
