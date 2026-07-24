<?php

declare(strict_types=1);

use Capell\Core\Facades\CapellCore;
use Capell\Core\Providers\CapellServiceProvider;
use Capell\Core\Support\CapellCoreManager;
use Capell\Core\Support\Packages\PackageSurfaceRegistrar;
use Illuminate\Database\Eloquent\Model;

final class PackageSurfaceBindingOrderModel extends Model {}

final class EarlyPackageSurfaceBindingOrderModel extends Model {}

it('adopts a manager resolved before provider registration without losing its surfaces', function (): void {
    app()->offsetUnset(CapellCoreManager::class);
    app()->forgetInstance(PackageSurfaceRegistrar::class);
    CapellCore::clearResolvedInstance(CapellCoreManager::class);

    $earlyManager = CapellCore::getFacadeRoot();

    expect($earlyManager)->toBeInstanceOf(CapellCoreManager::class);
    assert($earlyManager instanceof CapellCoreManager);

    $earlyManager->registerModels([EarlyPackageSurfaceBindingOrderModel::class]);

    (new CapellServiceProvider(app()))->registeringPackage();

    $surface = resolve(PackageSurfaceRegistrar::class);
    $surface->models([PackageSurfaceBindingOrderModel::class]);

    expect(resolve(CapellCoreManager::class))
        ->toBe($earlyManager)
        ->and(resolve('capell-admin'))->toBe($earlyManager)
        ->and(CapellCore::getFacadeRoot())->toBe($earlyManager)
        ->and(CapellCore::getModels())
        ->toHaveKey('EarlyPackageSurfaceBindingOrderModel', EarlyPackageSurfaceBindingOrderModel::class)
        ->toHaveKey('PackageSurfaceBindingOrderModel', PackageSurfaceBindingOrderModel::class);
});

it('shares one manager instance across its class, public alias, and facade', function (): void {
    $manager = resolve(CapellCoreManager::class);
    $surface = resolve(PackageSurfaceRegistrar::class);

    $surface->models([PackageSurfaceBindingOrderModel::class]);

    expect(app()->getBindings())
        ->toHaveKey(CapellCoreManager::class)
        ->and(app()->getBindings()[CapellCoreManager::class]['shared'])->toBeTrue()
        ->and(resolve('capell-admin'))->toBe($manager)
        ->and(CapellCore::getFacadeRoot())->toBe($manager)
        ->and(CapellCore::getModels())
        ->toHaveKey('PackageSurfaceBindingOrderModel', PackageSurfaceBindingOrderModel::class)
        ->and(resolve(CapellCoreManager::class))->toBe($manager);
});
