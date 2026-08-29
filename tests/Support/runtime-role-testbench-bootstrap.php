<?php

declare(strict_types=1);

use Capell\Tests\Support\IsolatedTestbenchSkeleton;
use Capell\Tests\Support\RuntimeRoleTestbenchApplication;
use Illuminate\Foundation\Application;

use function Orchestra\Sidekick\Filesystem\join_paths;

use Orchestra\Testbench\Foundation\Bootstrap\SyncTestbenchCachedRoutes;
use Orchestra\Testbench\Foundation\Config;
use Orchestra\Testbench\Workbench\Workbench;

$root = dirname(__DIR__, 2);
$config = Config::loadFromYaml(workingPath: $root, filename: 'testbench.yaml');
$basePath = IsolatedTestbenchSkeleton::basePath();

$hasEnvironmentFile = is_file(join_paths($basePath, '.env'));

$app = RuntimeRoleTestbenchApplication::create(
    basePath: $basePath,
    resolvingCallback: static function (Application $app) use ($config): void {
        // Testbench exposes this internal hook for bootstrapping its application factory.
        // @phpstan-ignore-next-line method.internal
        Workbench::startWithProviders($app, $config);
        Workbench::discoverRoutes($app, $config);
    },
    options: [
        'load_environment_variables' => $hasEnvironmentFile,
        'extra' => $config->getExtraAttributes(),
    ],
);

(new SyncTestbenchCachedRoutes)->bootstrap($app);

return $app;
