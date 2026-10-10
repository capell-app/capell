<?php

declare(strict_types=1);

use Capell\Tests\Support\ScriptFixture;
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

it('configures a loadable Testbench application before resolving the runtime role', function (string $script): void {
    $fixture = new ScriptFixture;
    $fixture->copy('scripts/configure-testbench-runtime-role.php');
    $fixture->copy('scripts/screenshots/configure-testbench-runtime-role.php');
    $fixture->write('entry/app.php', <<<'BOOTSTRAP'
<?php
use Orchestra\Testbench\Foundation\Application;
return Application::create(
    basePath: dirname(__DIR__) . '/application',
    options: ['extra' => ['dont-discover' => ['*']]],
);
BOOTSTRAP);
    $fixture->write('probe.php', <<<'PROBE'
<?php
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/entry/app.php';
try {
    echo json_encode([
    'role' => $app->make(Capell\Core\Support\Runtime\RuntimeRoleResolver::class)->role()->value,
    'settings_repository' => $app->make('config')->get('settings.default_repository'),
    'settings_cache' => $app->make('config')->get('settings.cache'),
    'settings_repositories' => $app->make('config')->get('settings.repositories'),
    ], JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage());
    exit(1);
}
PROBE);
    try {
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $configured = $fixture->php($script, [$fixture->root . '/entry/app.php']);
            expect($configured->getExitCode())->toBe(0, $configured->getErrorOutput());
            $loaded = $fixture->php('probe.php', environment: [
                'CAPELL_RUNTIME_ROLE' => 'public',
                'APP_CONFIG_CACHE' => false,
                'APP_PACKAGES_CACHE' => false,
                'APP_SERVICES_CACHE' => false,
                'APP_ROUTES_CACHE' => false,
                'APP_EVENTS_CACHE' => false,
            ]);
            expect($loaded->getExitCode())->toBe(0, $loaded->getErrorOutput());
            $result = json_decode($loaded->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            expect($result['role'])->toBe('public')
                ->and($result['settings_repository'])->toBe('database')
                ->and($result['settings_cache'])->toBeArray()
                ->and($result['settings_repositories'])->toBeArray();
        }
    } finally {
        $fixture->close();
    }
})->with([
    'portable helper' => 'scripts/configure-testbench-runtime-role.php',
    'screenshot compatibility helper' => 'scripts/screenshots/configure-testbench-runtime-role.php',
]);
