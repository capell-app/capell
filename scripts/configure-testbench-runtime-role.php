<?php

declare(strict_types=1);

$bootstrapPath = $argv[1] ?? dirname(__DIR__) . '/vendor/orchestra/testbench-core/laravel/bootstrap/app.php';
$bootstrap = file_get_contents($bootstrapPath);

if (! is_string($bootstrap)) {
    throw new RuntimeException(sprintf('Unable to read Testbench bootstrap [%s].', $bootstrapPath));
}

$configuredApplication = 'RuntimeRoleTestbenchApplication::create(';

if (str_contains($bootstrap, $configuredApplication)) {
    return;
}

$bootstrap = str_replace(
    "use Capell\\Core\\Support\\Runtime\\RuntimeRoleBootstrap;\n\n",
    '',
    $bootstrap,
    $removedLegacyImport,
);
$bootstrap = str_replace(
    "\nRuntimeRoleBootstrap::configureResolvedApplication(\$app);",
    '',
    $bootstrap,
    $removedLegacyConfiguration,
);

if (! str_contains($bootstrap, 'use Capell\\Tests\\Support\\RuntimeRoleTestbenchApplication;')) {
    $bootstrap = str_replace(
        "use Orchestra\\Testbench\\Foundation\\Application;\n",
        "use Capell\\Tests\\Support\\RuntimeRoleTestbenchApplication;\nuse Orchestra\\Testbench\\Foundation\\Application;\n",
        $bootstrap,
        $useStatementReplacements,
    );

    if ($useStatementReplacements !== 1) {
        throw new RuntimeException(sprintf('Unable to add the runtime role application import to [%s].', $bootstrapPath));
    }
}

$bootstrap = str_replace(
    'return Application::create(',
    'return RuntimeRoleTestbenchApplication::create(',
    $bootstrap,
    $applicationCreationReplacements,
);

if ($applicationCreationReplacements !== 1) {
    throw new RuntimeException(sprintf('Unable to locate the Testbench application factory in [%s].', $bootstrapPath));
}

if (file_put_contents($bootstrapPath, $bootstrap) === false) {
    throw new RuntimeException(sprintf('Unable to configure the Testbench runtime role application in [%s].', $bootstrapPath));
}
