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

if (! is_string($basePath)) {
    throw new RuntimeException('Unable to resolve the isolated Testbench skeleton path.');
}

$hasEnvironmentFile = is_file(join_paths($basePath, '.env'));

$app = RuntimeRoleTestbenchApplication::create(
    basePath: $basePath,
    options: [
        'load_environment_variables' => $hasEnvironmentFile,
        'extra' => $config->getExtraAttributes(),
    ],
    resolvingCallback: static function (Application $app) use ($config): void {
        Workbench::startWithProviders($app, $config);
        Workbench::discoverRoutes($app, $config);
    },
);

(new SyncTestbenchCachedRoutes)->bootstrap($app);

return $app;
