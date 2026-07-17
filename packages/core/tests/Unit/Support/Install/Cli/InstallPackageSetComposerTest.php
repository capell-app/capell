<?php

declare(strict_types=1);

use Capell\Core\Facades\CapellCore;
use Capell\Core\Support\Install\Cli\InstallPackageSetComposer;
use Capell\Core\Support\Install\PackageWorkflowPlanner;
use Capell\Core\Support\Install\ThemePackageCandidates;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

beforeEach(function (): void {
    CapellCore::clearPackages();
});

it('preserves console package option precedence for demo and install-time package composition', function (): void {
    $composer = installPackageSetComposer();

    expect($composer->shouldIncludeDemoPackagesAfterSelection(
        interactive: false,
        packagesOption: null,
        packageModeOption: null,
        allPackages: false,
        useFreshDemoPackageDefaults: false,
    ))->toBeTrue()
        ->and($composer->shouldIncludeDemoPackagesAfterSelection(
            interactive: true,
            packagesOption: null,
            packageModeOption: null,
            allPackages: false,
            useFreshDemoPackageDefaults: false,
        ))->toBeFalse()
        ->and($composer->shouldIncludeDemoPackagesAfterSelection(
            interactive: true,
            packagesOption: '',
            packageModeOption: null,
            allPackages: false,
            useFreshDemoPackageDefaults: false,
        ))->toBeTrue()
        ->and($composer->installTimePackageNames(
            selectedPackageNames: ['vendor/not-trusted'],
            packageMode: 'all',
            allPackages: false,
            useFreshDemoPackageDefaults: false,
        ))->toBe([
            'capell-app/admin',
            'capell-app/frontend',
            'capell-app/marketplace',
        ]);
});

it('normalizes legacy theme keys and retains the unknown-theme error contract', function (): void {
    $composer = installPackageSetComposer();
    $errors = [];

    expect($composer->resolveThemeSelection(
        themeOption: 'foundation',
        interactive: false,
        useFreshDemoDefaults: false,
        writeError: function (string $message) use (&$errors): void {
            $errors[] = $message;
        },
    ))->toBe([ThemePackageCandidates::DEFAULT_KEY, null])
        ->and($composer->resolveThemeSelection(
            themeOption: 'missing-theme',
            interactive: false,
            useFreshDemoDefaults: false,
            writeError: function (string $message) use (&$errors): void {
                $errors[] = $message;
            },
        ))->toBe([null, SymfonyCommand::FAILURE])
        ->and($errors)->toHaveCount(1)
        ->and($errors[0])->toStartWith('Unknown theme [missing-theme]. Available themes: ');
});

function installPackageSetComposer(): InstallPackageSetComposer
{
    $planner = new PackageWorkflowPlanner;

    return new InstallPackageSetComposer(
        $planner,
        new ThemePackageCandidates($planner),
    );
}
