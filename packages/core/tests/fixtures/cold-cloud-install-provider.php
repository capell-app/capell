<?php

declare(strict_types=1);

use Capell\Core\Actions\InstallPackageAction;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Providers\CapellServiceProvider;
use Capell\Core\Support\OutboundEventRegistry;
use Capell\Core\Tests\Support\Fixtures\Autoload\ColdCloudInstallRuntimeProviderFixture;
use Illuminate\Contracts\Console\Kernel;
use Orchestra\Testbench\Foundation\Application as TestbenchApplication;
use Symfony\Component\Filesystem\Filesystem;

$arguments = $_SERVER['argv'] ?? [];

if (! is_array($arguments)
    || ! isset($arguments[1], $arguments[2], $arguments[3])
    || ! is_string($arguments[1])
    || ! is_string($arguments[2])
    || ! is_string($arguments[3])) {
    throw new InvalidArgumentException('Expected workspace, application, and SQLite paths.');
}

$workspacePath = $arguments[1];
$basePath = $arguments[2];
$databasePath = $arguments[3];

require $workspacePath . '/vendor/autoload.php';
$packageName = 'vendor/cold-cloud-install';
$eventName = 'cold-cloud-install.surface-ready';

try {
    $filesystem = new Filesystem;
    $filesystem->mirror(
        (string) Orchestra\Testbench\default_skeleton_path(),
        $basePath,
        null,
        ['override' => true, 'delete' => false],
    );
    $filesystem->mkdir(dirname($databasePath));
    $filesystem->touch($databasePath);
    file_put_contents($basePath . '/.env', implode(PHP_EOL, [
        'APP_ENV=testing',
        'APP_KEY=base64:' . base64_encode(random_bytes(32)),
        'DB_CONNECTION=sqlite',
        'DB_DATABASE=' . $databasePath,
        'CACHE_STORE=array',
        'QUEUE_CONNECTION=sync',
        '',
    ]));

    $manifest = [
        'manifest-version' => 3,
        'name' => $packageName,
        'slug' => 'cold-cloud-install',
        'displayName' => 'Cold Cloud Install',
        'kind' => 'package',
        'capellApiVersion' => '^1.0',
        'version' => '1.0.0',
        'description' => 'Cold cloud install test fixture.',
        'product' => ['group' => 'Tests', 'tier' => 'free', 'bundle' => null],
        'surfaces' => [],
        'dependencies' => ['requires' => [], 'supports' => [], 'conflicts' => []],
        'providers' => [
            'metadata' => [],
            'install' => [],
            'runtime' => [ColdCloudInstallRuntimeProviderFixture::class],
            'auth' => [],
            'admin' => [],
            'frontend' => [],
        ],
        'contributes' => [],
        'database' => ['migrations' => false, 'settings' => false, 'requiredTables' => []],
        'commands' => ['install' => null, 'setup' => null, 'demo' => null, 'doctor' => null],
        'settings' => [],
        'permissions' => [],
        'capabilities' => [],
        'performance' => [
            'frontendRenderBudgetMs' => 0,
            'adminQueryBudget' => 0,
            'cacheTags' => [],
            'cacheSafety' => [
                'cacheable' => false,
                'variesBy' => [],
                'sensitiveOutput' => false,
                'invalidationSources' => [],
                'queueInvalidation' => false,
            ],
        ],
        'healthChecks' => [],
        'commercial' => [
            'proposedLicense' => 'free',
            'requestedCertification' => 'community',
            'supportPolicy' => 'community',
            'privateDocsRequested' => false,
        ],
        'marketplace' => [
            'summary' => 'Cold cloud install test fixture.',
            'screenshots' => [],
            'categories' => ['tests'],
        ],
    ];

    $cachePath = $basePath . '/bootstrap/cache/capell-package-manifests.php';
    $filesystem->mkdir(dirname($cachePath));
    file_put_contents($cachePath, '<?php return ' . var_export([$packageName => $manifest], true) . ';' . PHP_EOL);

    $app = TestbenchApplication::create(
        basePath: $basePath,
        options: [
            'load_environment_variables' => true,
            'extra' => [
                'providers' => [CapellServiceProvider::class],
                'dont-discover' => ['*'],
            ],
        ],
    );

    $outboundEvents = $app->make(OutboundEventRegistry::class);

    if (! $outboundEvents->isFrozen() || ! $outboundEvents->has($eventName)) {
        throw new RuntimeException('The selected runtime provider did not register its boot-frozen surface during cold boot.');
    }

    /** @var Kernel $kernel */
    $kernel = $app->make(Kernel::class);

    foreach ([
        $workspacePath . '/packages/core/database/migrations/2026_05_10_190832_24_create_capell_extensions_table.php',
        $workspacePath . '/packages/core/database/migrations/2026_08_05_000001_add_auto_update_policy_to_capell_extensions.php',
        $workspacePath . '/packages/core/database/migrations/2026_08_14_000001_add_provider_recovery_to_capell_extensions.php',
    ] as $migrationPath) {
        if ($kernel->call('migrate', ['--path' => $migrationPath, '--realpath' => true, '--force' => true]) !== 0) {
            throw new RuntimeException(sprintf('Migration failed: %s', $kernel->output()));
        }
    }

    InstallPackageAction::run(CapellCore::getPackage($packageName));

    if (! CapellCore::isPackageInstalled($packageName)) {
        throw new RuntimeException('InstallPackageAction did not record the cloud-selected package as installed.');
    }

    echo json_encode([
        'event_registered_before_install' => $outboundEvents->has($eventName),
        'event_registry_frozen' => $outboundEvents->isFrozen(),
        'package_installed' => CapellCore::isPackageInstalled($packageName),
    ], JSON_THROW_ON_ERROR);
} catch (Throwable $throwable) {
    throw $throwable;
}
