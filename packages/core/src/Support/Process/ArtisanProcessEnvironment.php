<?php

declare(strict_types=1);

namespace Capell\Core\Support\Process;

final class ArtisanProcessEnvironment
{
    /**
     * @param  array<string, string>|null  $environment
     * @return array<string, string|false>
     */
    public static function prepare(?array $environment = null): array
    {
        // The calling process (a Pest worker resolving its own runtime-role
        // cache paths, for example) may carry these in $_ENV. Symfony Process
        // inherits the parent environment by default, so a spawned artisan
        // child would otherwise read or overwrite the caller's cache files
        // instead of resolving its own from its own base_path(). `false`
        // is Symfony's explicit-unset sentinel: it drops the key rather than
        // forwarding whatever the caller inherited.
        $environment = array_merge($environment ?? [], [
            'APP_CONFIG_CACHE' => false,
            'APP_PACKAGES_CACHE' => false,
            'APP_SERVICES_CACHE' => false,
            'APP_ROUTES_CACHE' => false,
            'APP_EVENTS_CACHE' => false,
        ]);

        $basePath = str_replace('\\', '/', base_path());

        if (! str_contains($basePath, 'testbench-skeletons')
            && ! str_contains($basePath, '/vendor/orchestra/testbench-core/laravel')) {
            return $environment;
        }

        $testbenchPackagePathFunction = 'Orchestra\\Testbench\\package_path';

        $workingPath = function_exists($testbenchPackagePathFunction)
            ? $testbenchPackagePathFunction()
            : dirname(__DIR__, 5);

        return array_merge($environment, [
            'TESTBENCH_WORKING_PATH' => $workingPath,
        ]);
    }
}
