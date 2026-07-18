<?php

declare(strict_types=1);

namespace Capell\Admin\Facades;

use Capell\Admin\Data\Dashboard\CapellOverviewStatData;
use Capell\Admin\Data\MarketingStudioActionData;
use Capell\Admin\Data\UserMenu\UserMenuItemData;
use Capell\Admin\Enums\DashboardEnum;
use Capell\Admin\Support\CapellAdminManager;
use Closure;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\Widget;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Facade;

/**
 * @mixin CapellAdminManager
 *
 * @method static void registerDashboardFilamentWidget(string $widgetClass, DashboardEnum ...$dashboards)
 * @method static list<class-string<Widget>> getDashboardFilamentWidgets(DashboardEnum $dashboard)
 * @method static void registerMarketingStudioAction(MarketingStudioActionData $action)
 * @method static array<string, list<MarketingStudioActionData>> getMarketingStudioActions()
 * @method static void registerUserMenuItem(string $key, string|Closure $label, string|Heroicon|null $icon = null, string|Closure|null $url = null, int|string|Closure|null $badge = null, string|Closure|null $badgeColor = null, bool|Closure $visible = true, int $sort = 100, ?string $group = null)
 * @method static array<string, UserMenuItemData> getUserMenuItemDefinitions()
 * @method static array<string, Action> getUserMenuItems(?Authenticatable $user = null)
 * @method static void clearUserMenuItems()
 * @method static list<CapellOverviewStatData> getOverviewStats(bool $onlyEnabled = true)
 * @method static list<array{key: string, label: string, group: string, description?: string|null}> getOverviewStatSettings()
 * @method static list<string> getDefaultEnabledOverviewStatKeys()
 * @method static list<string> getOverviewStatKeys()
 */
class CapellAdmin extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return CapellAdminManager::class;
    }
}
