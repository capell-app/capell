<?php

declare(strict_types=1);

use Capell\Admin\Contracts\Bridges\AdminBridge;
use Capell\Admin\Data\Bridges\AdminBridgeContextData;
use Capell\Admin\Support\AdminRuntimeActivator;
use Capell\Admin\Support\Bridges\AdminBridgeRegistrar;
use Capell\Admin\Support\Bridges\AdminBridgeRegistry;

it('activates built-ins and registered bridge packages exactly once', function (): void {
    $registry = new AdminBridgeRegistry;
    $registry->register('vendor/first', AdminRuntimeActivatorTestBridge::class);
    $registry->register('vendor/second', AdminRuntimeActivatorTestBridge::class);

    $builtInActivations = 0;
    $bootedPackages = [];
    $activator = new AdminRuntimeActivator(
        $registry,
        function () use (&$builtInActivations): void {
            $builtInActivations++;
        },
        function (string $packageName) use (&$bootedPackages): void {
            $bootedPackages[] = $packageName;
        },
    );

    $activator->activate();
    $activator->activate();

    expect($activator->isActivated())->toBeTrue()
        ->and($builtInActivations)->toBe(1)
        ->and($bootedPackages)->toBe(['vendor/first', 'vendor/second']);
});

it('does not recurse while activation is in progress', function (): void {
    $registry = new AdminBridgeRegistry;
    $activator = null;
    $builtInActivations = 0;
    $activator = new AdminRuntimeActivator(
        $registry,
        function () use (&$activator, &$builtInActivations): void {
            $builtInActivations++;
            $activator?->activate();
        },
        static function (string $packageName): void {},
    );

    $activator->activate();

    expect($activator->isActivated())->toBeTrue()
        ->and($builtInActivations)->toBe(1);
});

final class AdminRuntimeActivatorTestBridge implements AdminBridge
{
    public function isEnabled(AdminBridgeContextData $context): bool
    {
        return true;
    }

    public function register(AdminBridgeRegistrar $registrar, AdminBridgeContextData $context): void {}
}
