<?php

declare(strict_types=1);

use Capell\Core\Facades\CapellCore;
use Capell\Core\Support\CapellCoreManager;

it('shares one manager instance across its class, public alias, and facade', function (): void {
    $manager = resolve(CapellCoreManager::class);

    expect(app()->getBindings())
        ->toHaveKey(CapellCoreManager::class)
        ->and(app()->getBindings()[CapellCoreManager::class]['shared'])->toBeTrue()
        ->and(resolve('capell-admin'))->toBe($manager)
        ->and(CapellCore::getFacadeRoot())->toBe($manager)
        ->and(resolve(CapellCoreManager::class))->toBe($manager);
});
