<?php

declare(strict_types=1);

use Capell\Core\Support\Install\Cli\FilamentAdminInstallPreflight;
use Capell\Core\Support\Install\NullProgressReporter;

it('skips Filament setup when the admin package is not selected', function (): void {
    $errors = [];

    $ready = (new FilamentAdminInstallPreflight)->ensureReady(
        packages: collect(),
        interactive: false,
        useFreshDemoDefaults: false,
        reporter: new NullProgressReporter,
        writeError: function (string $message) use (&$errors): void {
            $errors[] = $message;
        },
    );

    expect($ready)->toBeTrue()
        ->and($errors)->toBe([]);
});
