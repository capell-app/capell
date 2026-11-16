<?php

declare(strict_types=1);

use Capell\Core\Data\PackageData;
use Capell\Core\Enums\PackageTypeEnum;
use Capell\Core\Support\Packages\TrustedCorePackages;

it('preselects installable core packages by default', function (): void {
    expect(TrustedCorePackages::isDefaultInstallSelection('capell-app/admin'))->toBeTrue()
        ->and(TrustedCorePackages::isDefaultInstallSelection('capell-app/frontend'))->toBeTrue()
        ->and(TrustedCorePackages::isDefaultInstallSelection('capell-app/marketplace'))->toBeTrue()
        ->and(TrustedCorePackages::isDefaultInstallSelection('capell-app/welcome-tour'))->toBeFalse()
        ->and(TrustedCorePackages::isDefaultInstallSelection('capell-app/core'))->toBeFalse()
        ->and(TrustedCorePackages::isDefaultInstallSelection('capell-app/installer'))->toBeFalse();
});

it('preselects a package outside the aggregate only when its manifest or catalogue entry says so', function (): void {
    $declaresDefault = new PackageData(name: 'vendor/tour', type: PackageTypeEnum::Plugin, defaultSelected: true);
    $staysUnselected = new PackageData(name: 'vendor/other', type: PackageTypeEnum::Plugin);
    $aggregateMember = new PackageData(name: 'capell-app/admin', type: PackageTypeEnum::Package);

    expect(TrustedCorePackages::isDefaultInstallSelectionPackage($declaresDefault))->toBeTrue()
        ->and(TrustedCorePackages::isDefaultInstallSelectionPackage($staysUnselected))->toBeFalse()
        ->and(TrustedCorePackages::isDefaultInstallSelectionPackage($aggregateMember))->toBeTrue()
        ->and(TrustedCorePackages::contains('vendor/tour'))->toBeFalse();
});
