<?php

declare(strict_types=1);

use Capell\Core\Support\Packages\TrustedCorePackages;

it('preselects installable core packages by default', function (): void {
    expect(TrustedCorePackages::isDefaultInstallSelection('capell-app/admin'))->toBeTrue()
        ->and(TrustedCorePackages::isDefaultInstallSelection('capell-app/frontend'))->toBeTrue()
        ->and(TrustedCorePackages::isDefaultInstallSelection('capell-app/marketplace'))->toBeTrue()
        ->and(TrustedCorePackages::isDefaultInstallSelection('capell-app/core'))->toBeFalse()
        ->and(TrustedCorePackages::isDefaultInstallSelection('capell-app/installer'))->toBeFalse();
});
