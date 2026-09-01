<?php

declare(strict_types=1);

use Capell\Core\Contracts\Extensions\RecordsExtensionContributionReceipt;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Providers\CapellServiceProvider;
use Capell\Core\Support\CapellCoreManager;
use Capell\Core\Support\Packages\PackageSurfaceRegistrar;
use Illuminate\Container\Container as LaravelContainer;
use Illuminate\Contracts\Container\Container as ContainerContract;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

final class PackageSurfaceBindingOrderModel extends Model
{
    use HasFactory;
}

final class EarlyPackageSurfaceBindingOrderModel extends Model
{
    use HasFactory;
}

it('resolves the surface registrar before its provider binding has been registered', function (): void {
    $container = new LaravelContainer;
    $container->instance(ContainerContract::class, $container);

    expect($container->bound(PackageSurfaceRegistrar::class))->toBeFalse()
        ->and($container->bound(RecordsExtensionContributionReceipt::class))->toBeFalse()
        ->and($container->getAlias(RecordsExtensionContributionReceipt::class))
        ->toBe(RecordsExtensionContributionReceipt::class)
        ->and($container->make(PackageSurfaceRegistrar::class))
        ->toBeInstanceOf(PackageSurfaceRegistrar::class);
});

it('adopts a manager resolved before provider registration without losing its surfaces', function (): void {
    app()->offsetUnset(CapellCoreManager::class);
    app()->forgetInstance(PackageSurfaceRegistrar::class);
    CapellCore::clearResolvedInstance(CapellCoreManager::class);

    $earlyManager = CapellCore::getFacadeRoot();

    expect($earlyManager)->toBeInstanceOf(CapellCoreManager::class);
    assert($earlyManager instanceof CapellCoreManager);

    $earlyManager->registerModels([EarlyPackageSurfaceBindingOrderModel::class]);

    new CapellServiceProvider(app())->registeringPackage();

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
