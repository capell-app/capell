<?php

declare(strict_types=1);

use Capell\Core\Support\Process\ArtisanProcessEnvironment;

it('forwards the package working path to fresh Testbench Artisan processes', function (): void {
    expect(ArtisanProcessEnvironment::prepare())
        ->toBe([
            'TESTBENCH_WORKING_PATH' => \Orchestra\Testbench\package_path(),
        ]);
});

it('preserves an existing process environment', function (): void {
    expect(ArtisanProcessEnvironment::prepare([
        'PATH' => '/usr/bin',
        'TESTBENCH_WORKING_PATH' => '/stale/path',
    ]))->toBe([
        'PATH' => '/usr/bin',
        'TESTBENCH_WORKING_PATH' => \Orchestra\Testbench\package_path(),
    ]);
});
