<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

it('stages the committed frontend build for isolated Testbench workers', function (): void {
    $script = file_get_contents(dirname(__DIR__, 2) . '/scripts/prepare-testbench-vendor-configs.php');

    expect($script)
        ->toBeString()
        ->toContain("'packages/frontend/publishes/build' => 'public/vendor/capell-frontend'");
});

it('stages third-party configuration required by isolated package providers', function (): void {
    $script = file_get_contents(dirname(__DIR__, 2) . '/scripts/prepare-testbench-vendor-configs.php');

    expect($script)
        ->toBeString()
        ->toContain("'spatie/laravel-activitylog/config/activitylog.php'");
});

it('configures the Testbench application factory before provider registration', function (): void {
    $root = dirname(__DIR__, 2);
    $temporary = sys_get_temp_dir() . '/capell-testbench-runtime-bootstrap-' . bin2hex(random_bytes(6)) . '.php';

    file_put_contents($temporary, <<<'PHP'
<?php

use Orchestra\Testbench\Foundation\Application;

return Application::create(
    resolvingCallback: static function ($app): void {},
);

PHP);

    try {
        $process = new Process([
            PHP_BINARY,
            'scripts/configure-testbench-runtime-role.php',
            $temporary,
        ], $root);
        $process->mustRun();

        $bootstrap = file_get_contents($temporary);

        expect($bootstrap)
            ->toBeString()
            ->toContain('use Capell\\Tests\\Support\\RuntimeRoleTestbenchApplication;')
            ->toContain('RuntimeRoleTestbenchApplication::create(')
            ->not->toContain('RuntimeRoleBootstrap::configureResolvedApplication($app);');

        $process->mustRun();

        expect(substr_count((string) file_get_contents($temporary), 'RuntimeRoleTestbenchApplication::create('))->toBe(1);
    } finally {
        if (is_file($temporary)) {
            unlink($temporary);
        }
    }
});
