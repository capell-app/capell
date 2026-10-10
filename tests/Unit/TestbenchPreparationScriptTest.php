<?php

declare(strict_types=1);

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

function withTestbenchPreparationFixture(Closure $assert): void
{
    $root = dirname(__DIR__, 2);
    $directory = sys_get_temp_dir() . '/capell-testbench-preparation-' . bin2hex(random_bytes(8));
    $files = new Filesystem;
    $files->mkdir($directory . '/scripts');
    $files->copy($root . '/scripts/prepare-testbench-vendor-configs.php', $directory . '/scripts/prepare.php');
    foreach (['spatie/laravel-activitylog/config/activitylog.php', 'spatie/laravel-permission/config/permission.php', 'spatie/laravel-settings/config/settings.php', 'bezhansalleh/filament-shield/config/filament-shield.php'] as $path) {
        $files->dumpFile($directory . '/vendor/' . $path, '<?php return ' . var_export(['fixture' => $path], true) . ';');
    }

    $files->dumpFile($directory . '/packages/frontend/publishes/build/app.css', '.public-fixture { color: blue; }');
    $files->dumpFile($directory . '/packages/frontend/publishes/build/nested/app.js', 'window.publicFixture = true;');

    $process = new Process([PHP_BINARY, $directory . '/scripts/prepare.php'], $directory);
    try {
        expect($process->run())->toBe(0, $process->getErrorOutput());
        $assert($directory, $process);
    } finally {
        $files->remove($directory);
    }
}

it('stages usable frontend assets for an isolated Testbench application', function (): void {
    withTestbenchPreparationFixture(function (string $directory, Process $process): void {
        $public = $directory . '/vendor/orchestra/testbench-core/laravel/public/vendor/capell-frontend';
        expect(file_get_contents($public . '/app.css'))->toBe('.public-fixture { color: blue; }')
            ->and(file_get_contents($public . '/nested/app.js'))->toBe('window.publicFixture = true;');
        expect($process->run())->toBe(0)
            ->and(file_get_contents($public . '/app.css'))->toBe('.public-fixture { color: blue; }');
    });
});

it('stages loadable third-party configuration for isolated package providers', function (): void {
    withTestbenchPreparationFixture(function (string $directory): void {
        $relative = 'spatie/laravel-activitylog/config/activitylog.php';
        $source = require $directory . '/vendor/' . $relative;
        $staged = require $directory . '/vendor/orchestra/testbench-core/laravel/vendor/' . $relative;
        expect($staged)->toBe($source);
    });
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
