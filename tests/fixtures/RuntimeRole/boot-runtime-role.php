<?php

declare(strict_types=1);

use Capell\Core\Support\Runtime\RuntimeRoleBootstrap;
use Capell\Core\Support\Runtime\RuntimeRoleResolver;
use Capell\Tests\Fixtures\RuntimeRole\Filament\AuthoringRuntimeRoleProvider;
use Capell\Tests\Fixtures\RuntimeRole\FrontendPreviewRuntimeRoleProvider;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\BootProviders;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Foundation\Bootstrap\LoadEnvironmentVariables;
use Illuminate\Foundation\Bootstrap\RegisterFacades;
use Illuminate\Foundation\Bootstrap\RegisterProviders;
use Illuminate\Foundation\PackageManifest;
use Illuminate\Support\Env;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$role = $argv[1] ?? 'combined';
$fixtureState = $argv[2] ?? null;
$useStaleGeneratedManifests = $fixtureState === 'stale';
$useCustomProviders = $fixtureState === 'custom';
$useResolvedApplication = $fixtureState === 'resolved';
$basePath = sys_get_temp_dir() . '/capell-runtime-role-boot-' . bin2hex(random_bytes(6));
$files = new Filesystem;
$files->ensureDirectoryExists($basePath . '/bootstrap/cache');
$files->ensureDirectoryExists($basePath . '/config');
$providers = [
    FrontendPreviewRuntimeRoleProvider::class,
    AuthoringRuntimeRoleProvider::class,
];
$files->put(
    $basePath . '/bootstrap/providers.php',
    '<?php return ' . var_export($useCustomProviders ? [] : $providers, true) . ';',
);

if ($useStaleGeneratedManifests) {
    $files->ensureDirectoryExists($basePath . '/bootstrap/cache/capell-runtime/public');
    $files->put(
        $basePath . '/bootstrap/cache/packages.php',
        '<?php return ' . var_export([
            'vendor/stale-runtime-package' => ['providers' => $providers],
        ], true) . ';',
    );
    $files->put(
        $basePath . '/bootstrap/cache/capell-runtime/public/packages.php',
        '<?php return ' . var_export([
            'vendor/stale-runtime-package' => ['providers' => $providers],
        ], true) . ';',
    );
    $files->put(
        $basePath . '/bootstrap/cache/capell-runtime/public/providers.php',
        '<?php return ' . var_export($providers, true) . ';',
    );
    $files->put(
        $basePath . '/bootstrap/cache/capell-runtime/public/config.php',
        '<?php return ' . var_export([
            'app' => [
                'aliases' => [],
                'debug' => false,
                'env' => 'testing',
                'providers' => $providers,
                'timezone' => 'UTC',
            ],
        ], true) . ';',
    );
}

try {
    RegisterProviders::flushState();
    Env::getRepository()->set('CAPELL_RUNTIME_ROLE', $role);

    $application = $useCustomProviders
        ? Application::configure(basePath: $basePath)->withProviders($providers)->create()
        : new Application($basePath);
    if ($useResolvedApplication) {
        $application->bootstrapWith([
            LoadEnvironmentVariables::class,
            LoadConfiguration::class,
        ]);
        RuntimeRoleBootstrap::configureResolvedApplication($application);
        $application->bootstrapWith([
            RegisterFacades::class,
            RegisterProviders::class,
            BootProviders::class,
        ]);
    } else {
        RuntimeRoleBootstrap::configure($application);
        $application->bootstrapWith([
            LoadEnvironmentVariables::class,
            LoadConfiguration::class,
            RegisterFacades::class,
            RegisterProviders::class,
            BootProviders::class,
        ]);
    }

    $selection = $application->make(RuntimeRoleResolver::class)->selection();
    $loadedProviders = array_keys(array_filter(
        $application->getLoadedProviders(),
        static fn (bool $loaded): bool => $loaded,
    ));

    echo json_encode([
        'role' => $selection->role->value,
        'valid' => $selection->valid,
        'loaded_providers' => $loadedProviders,
        'config_cache' => $application->getCachedConfigPath(),
        'packages_cache' => $application->getCachedPackagesPath(),
        'services_cache' => $application->getCachedServicesPath(),
        'routes_cache' => $application->getCachedRoutesPath(),
        'events_cache' => $application->getCachedEventsPath(),
        'package_manifest' => $application->make(PackageManifest::class)::class,
    ], JSON_THROW_ON_ERROR);
} finally {
    RegisterProviders::flushState();
    $files->deleteDirectory($basePath);
}
