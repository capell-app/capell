<?php

declare(strict_types=1);

use Capell\Admin\Contracts\Bridges\AdminBridge;
use Capell\Admin\Data\Bridges\AdminBridgeContextData;
use Capell\Admin\Facades\CapellAdmin;
use Capell\Admin\Providers\Filament\AdminPanelProvider;
use Capell\Admin\Support\AdminRuntimeActivator;
use Capell\Admin\Support\Bridges\AdminBridgeRegistrar;
use Capell\Admin\Support\Bridges\AdminBridgeRegistry;
use Filament\Panel;

it('prepares route-visible declarations before activating request-only runtime work', function (): void {
    $registry = new AdminBridgeRegistry;
    $registry->register('vendor/first', AdminRuntimeActivatorTestBridge::class);
    $registry->register('vendor/second', AdminRuntimeActivatorTestBridge::class);

    $builtInPreparations = 0;
    $runtimeActivations = 0;
    $bootedPackages = [];
    $activator = new AdminRuntimeActivator(
        $registry,
        function () use (&$builtInPreparations): void {
            $builtInPreparations++;
        },
        function () use (&$runtimeActivations): void {
            $runtimeActivations++;
        },
        function (string $packageName) use (&$bootedPackages): void {
            $bootedPackages[] = $packageName;
        },
    );

    $activator->prepare();
    $activator->prepare();

    expect($activator->isPrepared())->toBeTrue()
        ->and($activator->isActivated())->toBeFalse()
        ->and($builtInPreparations)->toBe(1)
        ->and($runtimeActivations)->toBe(0)
        ->and($bootedPackages)->toBe(['vendor/first', 'vendor/second']);

    $activator->activate();
    $activator->activate();

    expect($activator->isActivated())->toBeTrue()
        ->and($builtInPreparations)->toBe(1)
        ->and($runtimeActivations)->toBe(1)
        ->and($bootedPackages)->toBe(['vendor/first', 'vendor/second']);
});

it('does not recurse while activation is in progress', function (): void {
    $registry = new AdminBridgeRegistry;
    $activator = null;
    $builtInPreparations = 0;
    $runtimeActivations = 0;
    $activator = new AdminRuntimeActivator(
        $registry,
        function () use (&$activator, &$builtInPreparations): void {
            $builtInPreparations++;
            $activator?->activate();
        },
        function () use (&$activator, &$runtimeActivations): void {
            $runtimeActivations++;
            $activator?->activate();
        },
        static function (string $packageName): void {},
    );

    $activator->activate();

    expect($activator->isActivated())->toBeTrue()
        ->and($builtInPreparations)->toBe(1)
        ->and($runtimeActivations)->toBe(1);
});

it('defers request-only runtime work until the panel boots', function (): void {
    $activator = resolve(AdminRuntimeActivator::class);
    $provider = new AdminPanelProvider(app());
    $panel = $provider->panel(Panel::make());

    expect($activator->isPrepared())->toBeTrue()
        ->and($activator->isActivated())->toBeFalse();

    $panel->boot();
    $panel->boot();

    expect($activator->isActivated())->toBeTrue();
});

it('activates on first direct access to runtime asset definitions', function (): void {
    $activator = resolve(AdminRuntimeActivator::class);

    expect($activator->isActivated())->toBeFalse()
        ->and(CapellAdmin::hasAsset('Page'))->toBeTrue()
        ->and($activator->isActivated())->toBeTrue();
});

final class AdminRuntimeActivatorTestBridge implements AdminBridge
{
    public function isEnabled(AdminBridgeContextData $context): bool
    {
        return true;
    }

    public function register(AdminBridgeRegistrar $registrar, AdminBridgeContextData $context): void {}
}
