<?php

declare(strict_types=1);

use Capell\Admin\Contracts\Support\FlagIconRenderer as FlagIconRendererContract;
use Capell\Admin\Facades\CapellAdmin;
use Capell\Admin\Providers\AdminServiceProvider;
use Capell\Admin\Support\Activity\ActivityResourceLinkRegistry;
use Capell\Admin\Support\AdminSurfaceContributionRegistry;
use Capell\Admin\Support\Bridges\AdminBridgeRegistrar;
use Capell\Admin\Support\Bridges\AdminBridgeRegistry;
use Capell\Admin\Support\CapellAdminManager;
use Capell\Admin\Support\Dashboard\DashboardFilamentWidgetRegistry;
use Capell\Admin\Support\Dashboard\OverviewStatRegistry;
use Capell\Admin\Support\Extensions\ExtensionPageRegistry;
use Capell\Admin\Support\MarketingStudio\MarketingStudioActionRegistry;
use Capell\Admin\Support\Notifications\AdminNotificationGroupRegistry;
use Capell\Admin\Support\Reports\ReportRegistry;
use Capell\Admin\Support\UserMenu\UserMenuItemRegistry;
use Illuminate\Support\HtmlString;

it('shares one manager and its injected registries across the container and facade', function (): void {
    CapellAdmin::clearResolvedInstance(CapellAdminManager::class);

    $manager = resolve(CapellAdminManager::class);

    expect(resolve(CapellAdminManager::class))->toBe($manager)
        ->and(CapellAdmin::getFacadeRoot())->toBe($manager)
        ->and(managerRegistry($manager, 'adminSurfaceRegistry'))->toBe(resolve(AdminSurfaceContributionRegistry::class))
        ->and(managerRegistry($manager, 'reportRegistry'))->toBe(resolve(ReportRegistry::class))
        ->and(managerRegistry($manager, 'dashboardWidgetRegistry'))->toBe(resolve(DashboardFilamentWidgetRegistry::class))
        ->and(managerRegistry($manager, 'marketingStudioActionRegistry'))->toBe(resolve(MarketingStudioActionRegistry::class))
        ->and(managerRegistry($manager, 'userMenuItemRegistry'))->toBe(resolve(UserMenuItemRegistry::class))
        ->and(managerRegistry($manager, 'overviewStatRegistry'))->toBe(resolve(OverviewStatRegistry::class));
});

it('owns concrete collection bindings while preserving substitutable contract overrides', function (): void {
    $ownedBindings = [
        ExtensionPageRegistry::class,
        AdminNotificationGroupRegistry::class,
        ActivityResourceLinkRegistry::class,
        AdminSurfaceContributionRegistry::class,
        ReportRegistry::class,
        DashboardFilamentWidgetRegistry::class,
        MarketingStudioActionRegistry::class,
        UserMenuItemRegistry::class,
        OverviewStatRegistry::class,
        AdminBridgeRegistry::class,
        AdminBridgeRegistrar::class,
    ];
    $sentinels = [];

    foreach ($ownedBindings as $ownedBinding) {
        $sentinels[$ownedBinding] = new stdClass;
        app()->instance($ownedBinding, $sentinels[$ownedBinding]);
    }

    $renderer = new class implements FlagIconRendererContract
    {
        public function render(?string $flag, ?string $label = null, string $style = '4x3', array $attributes = []): HtmlString
        {
            return new HtmlString('host renderer');
        }
    };
    app()->instance(FlagIconRendererContract::class, $renderer);

    $provider = app()->getProvider(AdminServiceProvider::class);
    assert($provider instanceof AdminServiceProvider);
    $provider->registeringPackage();
    CapellAdmin::clearResolvedInstance(CapellAdminManager::class);

    foreach ($ownedBindings as $ownedBinding) {
        expect(resolve($ownedBinding))
            ->not->toBe($sentinels[$ownedBinding])
            ->toBe(resolve($ownedBinding));
    }

    $manager = resolve(CapellAdminManager::class);

    expect(CapellAdmin::getFacadeRoot())->toBe($manager)
        ->and(managerRegistry($manager, 'adminSurfaceRegistry'))->toBe(resolve(AdminSurfaceContributionRegistry::class))
        ->and(managerRegistry($manager, 'reportRegistry'))->toBe(resolve(ReportRegistry::class))
        ->and(managerRegistry($manager, 'dashboardWidgetRegistry'))->toBe(resolve(DashboardFilamentWidgetRegistry::class))
        ->and(managerRegistry($manager, 'marketingStudioActionRegistry'))->toBe(resolve(MarketingStudioActionRegistry::class))
        ->and(managerRegistry($manager, 'userMenuItemRegistry'))->toBe(resolve(UserMenuItemRegistry::class))
        ->and(managerRegistry($manager, 'overviewStatRegistry'))->toBe(resolve(OverviewStatRegistry::class))
        ->and(resolve(FlagIconRendererContract::class))->toBe($renderer);
});

function managerRegistry(CapellAdminManager $manager, string $property): object
{
    $reflection = new ReflectionProperty($manager, $property);
    $registry = $reflection->getValue($manager);

    assert(is_object($registry));

    return $registry;
}
