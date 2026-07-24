<?php

declare(strict_types=1);

use Capell\Core\Facades\CapellCore;
use Capell\Core\Support\CapellCoreManager;
use Capell\Core\Support\Packages\PackageSurfaceRegistrar;
use Illuminate\Database\Eloquent\Model;

final class PackageSurfaceBindingOrderModel extends Model {}

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
