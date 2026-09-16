<?php

declare(strict_types=1);

use Capell\Core\Support\Process\ArtisanProcessEnvironment;

use function Orchestra\Testbench\package_path;

use Symfony\Component\Process\Process;

it('forwards the package working path to fresh Testbench Artisan processes', function (): void {
    expect(ArtisanProcessEnvironment::prepare())
        ->toBe([
            'APP_CONFIG_CACHE' => false,
            'APP_PACKAGES_CACHE' => false,
            'APP_SERVICES_CACHE' => false,
            'APP_ROUTES_CACHE' => false,
            'APP_EVENTS_CACHE' => false,
            'TESTBENCH_WORKING_PATH' => package_path(),
        ]);
});

it('preserves an existing process environment', function (): void {
    expect(ArtisanProcessEnvironment::prepare([
        'PATH' => '/usr/bin',
        'TESTBENCH_WORKING_PATH' => '/stale/path',
    ]))->toBe([
        'PATH' => '/usr/bin',
        'TESTBENCH_WORKING_PATH' => package_path(),
        'APP_CONFIG_CACHE' => false,
        'APP_PACKAGES_CACHE' => false,
        'APP_SERVICES_CACHE' => false,
        'APP_ROUTES_CACHE' => false,
        'APP_EVENTS_CACHE' => false,
    ]);
});

it('unsets an inherited runtime-role cache path rather than forwarding it to a spawned child', function (): void {
    expect(ArtisanProcessEnvironment::prepare([
        'APP_CONFIG_CACHE' => '/inherited/worker/config.php',
        'APP_PACKAGES_CACHE' => '/inherited/worker/packages.php',
        'APP_SERVICES_CACHE' => '/inherited/worker/services.php',
        'APP_ROUTES_CACHE' => '/inherited/worker/routes.php',
        'APP_EVENTS_CACHE' => '/inherited/worker/events.php',
    ]))->toBe([
        'APP_CONFIG_CACHE' => false,
        'APP_PACKAGES_CACHE' => false,
        'APP_SERVICES_CACHE' => false,
        'APP_ROUTES_CACHE' => false,
        'APP_EVENTS_CACHE' => false,
        'TESTBENCH_WORKING_PATH' => package_path(),
    ]);
});

it('does not let a spawned Artisan child inherit an ambient runtime-role cache path', function (): void {
    $originalEnvironment = $_ENV;

    $_ENV['APP_CONFIG_CACHE'] = '/inherited/worker/config.php';

    try {
        $process = new Process(
            [PHP_BINARY, '-r', 'echo getenv("APP_CONFIG_CACHE") === false ? "unset" : "set";'],
            base_path(),
            ArtisanProcessEnvironment::prepare(),
        );
        $process->mustRun();

        expect($process->getOutput())->toBe('unset');
    } finally {
        $_ENV = $originalEnvironment;
    }
});
