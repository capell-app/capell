<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

it('stages the committed frontend build for isolated Testbench workers', function (): void {
    $script = file_get_contents(dirname(__DIR__, 2) . '/scripts/prepare-testbench-vendor-configs.php');

    expect($script)
        ->toBeString()
        ->toContain("'packages/frontend/publishes/build' => 'public/vendor/capell-frontend'");
});

it('configures runtime role cache paths in the customised screenshot Testbench bootstrap', function (): void {
    $root = dirname(__DIR__, 2);
    $temporary = sys_get_temp_dir() . '/capell-testbench-runtime-bootstrap-' . bin2hex(random_bytes(6)) . '.php';

    file_put_contents($temporary, <<<'PHP'
<?php

Application::create(
        resolvingCallback: static function ($app) use ($config) {
            Workbench::startWithProviders($app, $config);
        },
);

$app = $createApp(realpath(join_paths(__DIR__, '..')));

unset($createApp);

(new SyncTestbenchCachedRoutes)->bootstrap($app);

return $app;
PHP);

    try {
        $process = new Process([
            PHP_BINARY,
            'scripts/screenshots/configure-testbench-runtime-role.php',
            $temporary,
        ], $root);
        $process->mustRun();

        $bootstrap = file_get_contents($temporary);

        expect($bootstrap)
            ->toBeString()
            ->toContain('RuntimeRoleBootstrap::configureResolvedApplication($app);')
            ->and(substr_count($bootstrap, 'RuntimeRoleBootstrap::configureResolvedApplication($app);'))->toBe(1);

        $process->mustRun();

        expect(substr_count((string) file_get_contents($temporary), 'RuntimeRoleBootstrap::configureResolvedApplication($app);'))->toBe(1);
    } finally {
        if (is_file($temporary)) {
            unlink($temporary);
        }
    }
});
