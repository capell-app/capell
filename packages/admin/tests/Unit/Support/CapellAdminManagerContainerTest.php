<?php

declare(strict_types=1);

use Capell\Admin\Facades\CapellAdmin;
use Capell\Admin\Support\AdminSurfaceContributionRegistry;
use Capell\Admin\Support\CapellAdminManager;
use Capell\Admin\Support\Dashboard\DashboardFilamentWidgetRegistry;
use Capell\Admin\Support\Dashboard\OverviewStatRegistry;
use Capell\Admin\Support\MarketingStudio\MarketingStudioActionRegistry;
use Capell\Admin\Support\Reports\ReportRegistry;
use Capell\Admin\Support\UserMenu\UserMenuItemRegistry;

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

function managerRegistry(CapellAdminManager $manager, string $property): object
{
    $reflection = new ReflectionProperty($manager, $property);
    $registry = $reflection->getValue($manager);

    assert(is_object($registry));

    return $registry;
}
