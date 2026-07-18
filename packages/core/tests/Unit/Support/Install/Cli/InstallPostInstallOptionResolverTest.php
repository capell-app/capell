<?php

declare(strict_types=1);

use Capell\Core\Support\Install\Cli\InstallPostInstallOptionResolver;
use Capell\Core\Support\Install\WelcomeRouteInstaller;

it('resolves explicit and non-interactive post-install decisions without prompts', function (): void {
    $resolver = new InstallPostInstallOptionResolver;

    $explicitChoice = $resolver->resolveDeveloperToolingChoice(
        developerToolingRequested: true,
        skipBoostInstall: true,
        developerToolingInstalled: false,
        interactive: false,
        useFreshDemoDefaults: false,
    );
    $nonInteractiveChoice = $resolver->resolveDeveloperToolingChoice(
        developerToolingRequested: false,
        skipBoostInstall: false,
        developerToolingInstalled: false,
        interactive: false,
        useFreshDemoDefaults: false,
    );

    expect($explicitChoice->installDeveloperTooling)->toBeTrue()
        ->and($explicitChoice->configureBoostDeveloperTooling)->toBeFalse()
        ->and($nonInteractiveChoice->installDeveloperTooling)->toBeFalse()
        ->and($nonInteractiveChoice->configureBoostDeveloperTooling)->toBeFalse()
        ->and($resolver->shouldRunNpmBuild(false, true, false))->toBeFalse()
        ->and($resolver->shouldRemoveInstallerPackage(true, true, false, false))->toBeTrue()
        ->and($resolver->shouldRemoveInstallerPackage(false, true, true, false))->toBeFalse();
});

it('does not resolve welcome-route changes without a frontend package', function (): void {
    $manualChanges = [];
    $warnings = [];

    $installWelcomeRoute = (new InstallPostInstallOptionResolver)->resolveWelcomeRoute(
        hasFrontend: false,
        installWelcomeRouteOption: false,
        interactive: true,
        welcomeRouteInstaller: resolve(WelcomeRouteInstaller::class),
        recordManualInstallChange: function (string $message) use (&$manualChanges): void {
            $manualChanges[] = $message;
        },
        writeWarning: function (string $message) use (&$warnings): void {
            $warnings[] = $message;
        },
    );

    expect($installWelcomeRoute)->toBeFalse()
        ->and($manualChanges)->toBe([])
        ->and($warnings)->toBe([]);
});
