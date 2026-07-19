<?php

declare(strict_types=1);

use Capell\Core\Facades\CapellCore;
use Capell\Marketplace\Actions\PackageIsAvailableForLifecycleAction;

it('identifies registered available packages which still need their lifecycle installed', function (): void {
    CapellCore::shouldReceive('hasPackage')->once()->with('capell-app/example')->andReturnTrue();
    CapellCore::shouldReceive('isPackageAvailable')->once()->with('capell-app/example')->andReturnTrue();
    CapellCore::shouldReceive('isPackageInstalled')->once()->with('capell-app/example')->andReturnFalse();

    expect(PackageIsAvailableForLifecycleAction::run('capell-app/example'))->toBeTrue();
});

it('rejects packages whose lifecycle is already installed', function (): void {
    CapellCore::shouldReceive('hasPackage')->once()->with('capell-app/example')->andReturnTrue();
    CapellCore::shouldReceive('isPackageAvailable')->once()->with('capell-app/example')->andReturnTrue();
    CapellCore::shouldReceive('isPackageInstalled')->once()->with('capell-app/example')->andReturnTrue();

    expect(PackageIsAvailableForLifecycleAction::run('capell-app/example'))->toBeFalse();
});
