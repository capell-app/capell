<?php

declare(strict_types=1);

namespace Capell\Admin\Facades;

use Capell\Admin\Support\CapellAdminManager;
use Illuminate\Support\Facades\Facade;

/**
 * @mixin CapellAdminManager
 *
 * @method static void registerDashboardFilamentWidget(string $widgetClass, \Capell\Admin\Enums\DashboardEnum ...$dashboards)
 * @method static array getDashboardFilamentWidgets(\Capell\Admin\Enums\DashboardEnum $dashboard)
 * @method static void registerMarketingStudioAction(\Capell\Admin\Data\MarketingStudioActionData $action)
 * @method static array getMarketingStudioActions()
 * @method static void registerUserMenuItem(string $key, string|\Closure $label, string|\Filament\Support\Icons\Heroicon|null $icon = null, string|\Closure|null $url = null, int|string|\Closure|null $badge = null, string|\Closure|null $badgeColor = null, bool|\Closure $visible = true, int $sort = 100, ?string $group = null)
 * @method static array getUserMenuItemDefinitions()
 * @method static array getUserMenuItems(?\Illuminate\Contracts\Auth\Authenticatable $user = null)
 * @method static void clearUserMenuItems()
 * @method static array getOverviewStats(bool $onlyEnabled = true)
 * @method static array getOverviewStatSettings()
 * @method static array getDefaultEnabledOverviewStatKeys()
 * @method static array getOverviewStatKeys()
 */
class CapellAdmin extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return CapellAdminManager::class;
    }
}
