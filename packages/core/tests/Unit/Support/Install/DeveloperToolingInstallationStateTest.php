<?php

declare(strict_types=1);

use Capell\Core\Enums\PackageRole;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Support\Install\DeveloperToolingInstallationState;
use Composer\InstalledVersions;

beforeEach(function (): void {
    CapellCore::clearPackages();
});

it('is not installed and names no tooling package when nothing declares the developer-tooling role', function (): void {
    $state = new DeveloperToolingInstallationState;

    expect($state->toolingPackageName())->toBeNull()
        ->and($state->isInstalled())->toBeFalse()
        ->and($state->missingPackageNames())->not->toContain('capell-app/agent-bridge');
});

it('requires the package that declares the role as well as Boost', function (): void {
    registerRolePackage('vendor/not-in-composer-tooling', PackageRole::DeveloperTooling);
    $state = new DeveloperToolingInstallationState;

    expect($state->toolingPackageName())->toBe('vendor/not-in-composer-tooling')
        ->and($state->isInstalled())->toBeFalse()
        ->and($state->missingPackageNames())->toContain('vendor/not-in-composer-tooling');
});

it('stops reporting the declaring package as missing once Composer has it', function (): void {
    // Composer's installed set always holds this package, so declaring the role on it stands in for the tooling package.
    registerRolePackage('pestphp/pest', PackageRole::DeveloperTooling);
    $state = new DeveloperToolingInstallationState;

    expect($state->toolingPackageName())->toBe('pestphp/pest')
        ->and($state->missingPackageNames())->not->toContain('pestphp/pest')
        ->and($state->isInstalled())->toBe(InstalledVersions::isInstalled(DeveloperToolingInstallationState::BOOST_PACKAGE_NAME));
});
