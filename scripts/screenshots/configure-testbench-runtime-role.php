<?php

declare(strict_types=1);

$bootstrapPath = $argv[1] ?? dirname(__DIR__, 2) . '/vendor/orchestra/testbench-core/laravel/bootstrap/app.php';
$bootstrap = file_get_contents($bootstrapPath);

if (! is_string($bootstrap)) {
    throw new RuntimeException(sprintf('Unable to read Testbench bootstrap [%s].', $bootstrapPath));
}

if (str_contains($bootstrap, 'RuntimeRoleBootstrap::configureResolvedApplication($app);')) {
    return;
}

$applicationCreation = <<<'PHP'
$app = $createApp(realpath(join_paths(__DIR__, '..')));
PHP;
$configuredApplicationCreation = $applicationCreation . PHP_EOL
    . 'RuntimeRoleBootstrap::configureResolvedApplication($app);';

if (! str_contains($bootstrap, $applicationCreation)) {
    throw new RuntimeException(sprintf('Unable to locate Testbench application creation in [%s].', $bootstrapPath));
}

if (! str_contains($bootstrap, 'use Capell\Core\Support\Runtime\RuntimeRoleBootstrap;')) {
    $bootstrap = str_replace(
        "<?php\n\n",
        "<?php\n\nuse Capell\\Core\\Support\\Runtime\\RuntimeRoleBootstrap;\n\n",
        $bootstrap,
        $useStatementReplacements,
    );

    if ($useStatementReplacements !== 1) {
        throw new RuntimeException(sprintf('Unable to add the runtime role bootstrap import to [%s].', $bootstrapPath));
    }
}

$bootstrap = str_replace("\n            RuntimeRoleBootstrap::configure(\$app);", '', $bootstrap);
$bootstrap = str_replace("\nRuntimeRoleBootstrap::configure(\$app);", '', $bootstrap);
$bootstrap = str_replace(
    $applicationCreation,
    $configuredApplicationCreation,
    $bootstrap,
    $configurationReplacements,
);

if ($configurationReplacements !== 1 || file_put_contents($bootstrapPath, $bootstrap) === false) {
    throw new RuntimeException(sprintf('Unable to configure runtime role cache paths in [%s].', $bootstrapPath));
}
