<?php

declare(strict_types=1);

use Capell\Admin\Contracts\Bridges\AdminBridge;
use Capell\Admin\Data\Bridges\AdminBridgeContextData;
use Capell\Admin\Enums\AdminNotificationGroupEnum;
use Capell\Admin\Facades\CapellAdmin;
use Capell\Admin\Filament\Pages\SiteAdminMetricsPage;
use Capell\Admin\Providers\Filament\AdminPanelProvider;
use Capell\Admin\Support\AdminRuntimeActivator;
use Capell\Admin\Support\Bridges\AdminBridgeRegistrar;
use Capell\Admin\Support\Bridges\AdminBridgeRegistry;
use Capell\Admin\Support\Notifications\AdminNotificationGroupRegistry;
use Filament\Panel;
use Illuminate\Support\Facades\DB;

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

it('prepares route-visible declarations for direct registry reads outside the bundled panel', function (): void {
    app()->forgetInstance(AdminRuntimeActivator::class);
    $activator = resolve(AdminRuntimeActivator::class);

    expect($activator->isPrepared())->toBeFalse()
        ->and(CapellAdmin::getAdminSurfaceRegistry()->pages())->toContain(SiteAdminMetricsPage::class)
        ->and($activator->isPrepared())->toBeTrue()
        ->and($activator->isActivated())->toBeFalse();
});

it('prepares deferred declarations before clearing registries', function (string $clearMethod): void {
    app()->forgetInstance(AdminRuntimeActivator::class);
    $activator = resolve(AdminRuntimeActivator::class);

    expect($activator->isPrepared())->toBeFalse();

    CapellAdmin::$clearMethod();

    expect($activator->isPrepared())->toBeTrue()
        ->and($activator->isActivated())->toBeFalse();
})->with([
    'user menu items' => 'clearUserMenuItems',
    'activity resource links' => 'clearActivityResourceLinks',
    'admin surface contributions' => 'clearAdminSurfaceContributions',
    'welcome tour steps' => 'clearWelcomeTourSteps',
]);

it('registers notification groups before non-panel consumers resolve the registry', function (): void {
    app()->forgetInstance(AdminRuntimeActivator::class);
    $activator = resolve(AdminRuntimeActivator::class);

    expect($activator->isPrepared())->toBeFalse()
        ->and(resolve(AdminNotificationGroupRegistry::class)->all())
        ->toHaveKeys(array_map(
            static fn (AdminNotificationGroupEnum $group): string => $group->value,
            AdminNotificationGroupEnum::cases(),
        ))
        ->and($activator->isPrepared())->toBeFalse();
});

it('boots bridges registered after runtime declarations have been prepared', function (): void {
    LateAdminRuntimeActivatorTestBridge::$registrations = 0;
    $activator = resolve(AdminRuntimeActivator::class);
    $activator->prepare();

    CapellAdmin::registerAdminBridge('vendor/late', LateAdminRuntimeActivatorTestBridge::class);

    expect(LateAdminRuntimeActivatorTestBridge::$registrations)->toBe(1);

    $activator->activate();
    CapellAdmin::registerAdminBridge('vendor/late', LateAdminRuntimeActivatorTestBridge::class);

    expect(LateAdminRuntimeActivatorTestBridge::$registrations)->toBe(1);
});

it('drains bridges registered by another bridge during preparation', function (): void {
    NestedAdminRuntimeActivatorTestBridge::$registrations = 0;
    $registry = resolve(AdminBridgeRegistry::class);
    $registry->register('vendor/outer', RegisteringAdminRuntimeActivatorTestBridge::class);

    resolve(AdminRuntimeActivator::class)->prepare();

    expect(NestedAdminRuntimeActivatorTestBridge::$registrations)->toBe(1)
        ->and($registry->classes('vendor/nested'))->toBe([NestedAdminRuntimeActivatorTestBridge::class]);
});

it('drains nested bridges registered after preparation', function (): void {
    NestedAdminRuntimeActivatorTestBridge::$registrations = 0;
    $activator = resolve(AdminRuntimeActivator::class);
    $activator->prepare();

    CapellAdmin::registerAdminBridge('vendor/late-outer', RegisteringAdminRuntimeActivatorTestBridge::class);

    expect(NestedAdminRuntimeActivatorTestBridge::$registrations)->toBe(1)
        ->and(resolve(AdminBridgeRegistry::class)->classes('vendor/nested'))
        ->toBe([NestedAdminRuntimeActivatorTestBridge::class]);
});

it('treats late bridge failures as terminal for the current worker', function (): void {
    FailingLateAdminRuntimeActivatorTestBridge::$registrations = 0;
    $activator = resolve(AdminRuntimeActivator::class);
    $activator->activate();

    expect(fn () => CapellAdmin::registerAdminBridge(
        'vendor/failing-late',
        FailingLateAdminRuntimeActivatorTestBridge::class,
    ))->toThrow(RuntimeException::class, 'Late bridge registration failed.')
        ->and(fn () => CapellAdmin::registerAdminBridge(
            'vendor/failing-late',
            FailingLateAdminRuntimeActivatorTestBridge::class,
        ))->toThrow(RuntimeException::class, 'Late bridge registration failed.')
        ->and(fn () => $activator->prepare())
        ->toThrow(RuntimeException::class, 'Late bridge registration failed.')
        ->and(fn () => $activator->activate())
        ->toThrow(RuntimeException::class, 'Late bridge registration failed.')
        ->and(FailingLateAdminRuntimeActivatorTestBridge::$registrations)->toBe(1);
});

it('treats failed preparation as terminal for the current worker', function (): void {
    $preparationAttempts = 0;
    $failure = new RuntimeException('Partial bridge registration failed.');
    $activator = new AdminRuntimeActivator(
        new AdminBridgeRegistry,
        function () use (&$preparationAttempts, $failure): void {
            $preparationAttempts++;

            throw $failure;
        },
        static function (): void {},
        static function (string $packageName): void {},
    );

    expect(fn () => $activator->prepare())->toThrow(RuntimeException::class, $failure->getMessage())
        ->and(fn () => $activator->prepare())->toThrow(RuntimeException::class, $failure->getMessage())
        ->and($preparationAttempts)->toBe(1)
        ->and($activator->isPrepared())->toBeFalse();
});

it('activates on first direct access to runtime asset definitions', function (): void {
    $activator = resolve(AdminRuntimeActivator::class);

    expect($activator->isActivated())->toBeFalse()
        ->and(CapellAdmin::hasAsset('Page'))->toBeTrue()
        ->and($activator->isActivated())->toBeTrue();
});

it('does not query the database while preparing or activating admin runtime', function (): void {
    $queries = [];
    DB::listen(function () use (&$queries): void {
        $queries[] = true;
    });

    $activator = resolve(AdminRuntimeActivator::class);
    $activator->prepare();
    $activator->activate();

    expect($queries)->toBe([]);
});

final class AdminRuntimeActivatorTestBridge implements AdminBridge
{
    public function isEnabled(AdminBridgeContextData $context): bool
    {
        return true;
    }

    public function register(AdminBridgeRegistrar $registrar, AdminBridgeContextData $context): void {}
}

final class LateAdminRuntimeActivatorTestBridge implements AdminBridge
{
    public static int $registrations = 0;

    public function isEnabled(AdminBridgeContextData $context): bool
    {
        return true;
    }

    public function register(AdminBridgeRegistrar $registrar, AdminBridgeContextData $context): void
    {
        self::$registrations++;
    }
}

final class RegisteringAdminRuntimeActivatorTestBridge implements AdminBridge
{
    public function isEnabled(AdminBridgeContextData $context): bool
    {
        return true;
    }

    public function register(AdminBridgeRegistrar $registrar, AdminBridgeContextData $context): void
    {
        $registrar->bridge('vendor/nested', NestedAdminRuntimeActivatorTestBridge::class);
    }
}

final class NestedAdminRuntimeActivatorTestBridge implements AdminBridge
{
    public static int $registrations = 0;

    public function isEnabled(AdminBridgeContextData $context): bool
    {
        return true;
    }

    public function register(AdminBridgeRegistrar $registrar, AdminBridgeContextData $context): void
    {
        self::$registrations++;
    }
}

final class FailingLateAdminRuntimeActivatorTestBridge implements AdminBridge
{
    public static int $registrations = 0;

    public function isEnabled(AdminBridgeContextData $context): bool
    {
        return true;
    }

    public function register(AdminBridgeRegistrar $registrar, AdminBridgeContextData $context): void
    {
        self::$registrations++;

        throw new RuntimeException('Late bridge registration failed.');
    }
}
