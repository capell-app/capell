<?php

declare(strict_types=1);

use Capell\Core\Support\Install\Cli\FilamentAdminInstallPreflight;
use Capell\Core\Support\Install\NullProgressReporter;
use Filament\Panel;
use Filament\PanelProvider;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;

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

it('treats a panel provider registered by a package as installed', function (): void {
    $testApplication = app();
    $application = new Application(sys_get_temp_dir());
    Container::setInstance($application);

    try {
        $preflight = new FilamentAdminInstallPreflight;
        expect($preflight->hasInstalledPanelProvider())->toBeFalse();

        $application->register(new class($application) extends PanelProvider
        {
            public function panel(Panel $panel): Panel
            {
                return $panel->id('package-registered');
            }
        });

        expect($preflight->hasInstalledPanelProvider())->toBeTrue();
    } finally {
        Container::setInstance($testApplication);
    }
});
