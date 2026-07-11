# Register a dashboard Filament widget

![Capell Register a dashboard Filament widget screenshot](../images/admin-dashboard.png)

Capell Admin uses Filament dashboard Filament widgets for product status, content activity, editor work queues, extension health, and marketing operations. This page is reference material for **package developers** registering new widgets. For the admin-facing task of choosing which widgets appear, see [Customize your dashboard](dashboard-customize.md).

For the lower-level API reference, see [Dashboard Filament widget customization](../../packages/admin/docs/dashboard-widget-customization.md).

## Pick The Right Surface

| Need                                                            | Use                                                                                             |
| --------------------------------------------------------------- | ----------------------------------------------------------------------------------------------- |
| Add a small count, status, or link to the Capell overview       | `CapellAdmin::registerOverviewStat(...)`                                                        |
| Add a table, chart, health panel, calendar, or operational tool | `CapellAdmin::registerDashboardFilamentWidget(...)`                                             |
| Add several package-owned admin surfaces                        | `AdminBridgeRegistrar::filamentDashboardWidget(...)` or `extensionDashboardFilamentWidget(...)` |
| Add a normal Filament widget to a resource/page surface         | `CapellAdmin::contributeToAdminSurface(...)` or an `AdminBridge`                                |
| Add a frontend Layout Builder widget                            | `LayoutWidgetRegistry::registerDefinition(...)` in Core/Frontend                                |

Small package metrics should usually be overview stats. A standalone widget is easier to justify when it has its own table, actions, filtering, or layout.

## Dashboard Buckets

Widgets are registered against `DashboardEnum` buckets:

| Dashboard enum                   | Surface                                                  |
| -------------------------------- | -------------------------------------------------------- |
| `DashboardEnum::Main`            | Installed admin dashboard at `/admin`.                   |
| `DashboardEnum::MarketingStudio` | Marketing Studio dashboard at `/admin/marketing-studio`. |
| `DashboardEnum::Extensions`      | Extensions dashboard at `/admin/extensions`.             |
| `DashboardEnum::SystemHealth`    | Reserved for system-health dashboard integrations.       |
| `DashboardEnum::NotInstalled`    | Setup/empty state before Admin has an installed site.    |

The normal admin dashboard page is `Capell\Admin\Filament\Pages\CapellDashboard`.

## Built-In Widgets

The Admin service provider and Filament plugin register the default dashboard Filament widgets. Registration decides which dashboard can use a widget. Settings decide whether a settings-gated widget is visible.

### Main Dashboard

| Widget                            | Default state | Purpose                                                         |
| --------------------------------- | ------------- | --------------------------------------------------------------- |
| `CapellAccountFilamentWidget`     | Enabled       | Filament account welcome card.                                  |
| `CapellInfoFilamentWidget`        | Enabled       | Filament version and documentation links.                       |
| `ListPagesFilamentWidget`         | Enabled       | Recently updated pages filtered by dashboard date range.        |
| `RecentActivityFilamentWidget`    | Enabled       | Recent admin activity when activity exists for the site.        |
| `MyWorkQueueFilamentWidget`       | Available     | Editor work queue from the configured data provider.            |
| `RecentlyPublishedFilamentWidget` | Available     | Recently published page list from the configured data provider. |
| `PageStatusFilamentWidget`        | Available     | Page status panel.                                              |
| `SiteStatsOverviewFilamentWidget` | Available     | Site stats overview.                                            |
| `UpdateAdvisoryFilamentWidget`    | Available     | Package/update advisory panel.                                  |

Resource pages can have their own alert widgets, such as page, site, type, language, and theme alerts. Those widgets live on the resource screens rather than the main dashboard.

### Marketing Studio

| Widget                                         | Purpose                                |
| ---------------------------------------------- | -------------------------------------- |
| `MarketingStudioQuickActionsFilamentWidget`    | Package-contributed marketing actions. |
| `MarketingStudioWorkQueueFilamentWidget`       | Marketing work queue.                  |
| `MarketingStudioLaunchReadinessFilamentWidget` | Launch-readiness status.               |
| `MarketingStudioTimelineFilamentWidget`        | Marketing timeline.                    |
| `MarketingStudioAdvancedFilamentWidget`        | Advanced marketing actions.            |

### Extensions

| Widget                                        | Purpose                                              |
| --------------------------------------------- | ---------------------------------------------------- |
| `ExtensionStatsOverviewFilamentWidget`        | Installed/uninstalled/attention/update/block counts. |
| `ExtensionHealthFilamentWidget`               | Extension health summary.                            |
| `ExtensionDiagnosticsFilamentWidget`          | Extension diagnostics.                               |
| `ExtensionUpdateReadinessFilamentWidget`      | Update readiness checks.                             |
| `ExtensionDependencyGraphFilamentWidget`      | Extension dependency graph.                          |
| `ExtensionRuntimeCompatibilityFilamentWidget` | Runtime compatibility checks.                        |
| `ExtensionActionsFilamentWidget`              | Extension quick actions.                             |
| `RecentlyChangedExtensionsFilamentWidget`     | Recent extension state changes.                      |
| `InstalledExtensionsFilamentWidget`           | Installed extension table.                           |

## Settings Model

Main dashboard settings live under **Settings -> Dashboard**. Marketing Studio and Extensions also expose dashboard customisation from their own pages when the current user can access settings.

Dashboard settings store two arrays on `Capell\Admin\Settings\AdminSettings`:

| Setting           | Purpose                                                                                |
| ----------------- | -------------------------------------------------------------------------------------- |
| `enabled_widgets` | Boolean visibility by widget settings key, such as `list_pages` or `extensions.stats`. |
| `widget_order`    | Sort order by widget settings key.                                                     |

Settings entries are discovered from:

1. Registered dashboard Filament widgets that expose `settingsKey()`.
2. Legacy built-in `FilamentWidgetEnum` values.
3. Overview stat groups registered with `CapellAdmin::registerOverviewStat()`.
4. Contributors tagged with `DashboardSettingsContributor::TAG`.

`SyncDashboardFilamentWidgetSettingsAction` adds missing keys, removes retired keys, restores the default layout when all known defaults were disabled, and keeps package widgets available without enabling every optional widget by accident.

The built-in numeric settings (`my_work_queue_limit`, `recently_published_limit`, and friends) are documented on the admin [Customize your dashboard](dashboard-customize.md#widget-limits) page.

## Add A Package Widget

Create a Filament widget and implement `CapellFilamentWidgetContract` when the widget should participate in Capell dashboard settings and role gating.

```php
<?php

declare(strict_types=1);

namespace Vendor\Package\Filament\Widgets;

use Capell\Admin\Contracts\CapellFilamentWidgetContract;
use Capell\Admin\Filament\Concerns\GatedByRoleAndSettings;
use Filament\Widgets\Widget;

final class PackageStatusWidget extends Widget implements CapellFilamentWidgetContract
{
    use GatedByRoleAndSettings;

    /** @var list<string> */
    protected static array $rolesConfigKeys = ['admin', 'super_admin'];

    protected static string $settingsKey = 'vendor_package.status';

    protected static ?int $sort = 80;

    protected string $view = 'vendor-package::widgets.status';
}
```

Register it from the package service provider:

```php
<?php

declare(strict_types=1);

namespace Vendor\Package\Providers;

use Capell\Admin\Enums\DashboardEnum;
use Capell\Admin\Facades\CapellAdmin;
use Illuminate\Support\ServiceProvider;
use Vendor\Package\Filament\Widgets\PackageStatusWidget;

final class PackageServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        CapellAdmin::registerDashboardFilamentWidget(PackageStatusWidget::class, DashboardEnum::Main);
    }
}
```

Registration makes the widget available to the dashboard bucket. The widget is visible only when `canView()` passes and its `settingsKey()` is enabled.

## Add A Settings Entry

Registered widgets with `settingsKey()` are discovered automatically. Use a tagged `DashboardSettingsContributor` when a package needs to override the label, group, or description for a discovered widget key.

```php
<?php

declare(strict_types=1);

namespace Vendor\Package\Admin;

use Capell\Admin\Contracts\DashboardSettingsContributor;

final class PackageDashboardSettingsContributor implements DashboardSettingsContributor
{
    public function settingsKeys(): array
    {
        return [
            [
                'key' => 'vendor_package.status',
                'label' => (string) __('vendor-package::dashboard.status_widget'),
                'group' => (string) __('vendor-package::dashboard.group'),
                'description' => (string) __('vendor-package::dashboard.status_widget_description'),
            ],
        ];
    }
}
```

Tag the contributor in the container:

```php
$this->app->tag([PackageDashboardSettingsContributor::class], DashboardSettingsContributor::TAG);
```

Use globally unique settings keys. Package-prefixed keys avoid collisions with core and other packages.

## Data Providers

Dashboard data providers should use `AdminDashboardDataRequestCache` when the same expensive data can be needed by a visibility check and widget hydration in one request. This keeps `canView()` checks from repeating the same queries as the rendered widget.

## Related Code

| File                                                                            | Purpose                                          |
| ------------------------------------------------------------------------------- | ------------------------------------------------ |
| `packages/admin/src/Filament/Pages/CapellDashboard.php`                         | Main dashboard page and date filter.             |
| `packages/admin/src/Support/CapellAdminManager.php`                             | Dashboard Filament widget registry and ordering. |
| `packages/admin/src/Enums/DashboardEnum.php`                                    | Dashboard buckets.                               |
| `packages/admin/src/Enums/FilamentWidgetEnum.php`                               | Legacy/core built-in widget enum.                |
| `packages/admin/src/Filament/Settings/Schemas/DashboardSettingsSchema.php`      | Dashboard settings UI.                           |
| `packages/admin/src/Actions/SyncDashboardFilamentWidgetSettingsAction.php`      | Default settings repair/sync.                    |
| `packages/admin/src/Actions/NormalizeDashboardFilamentWidgetSettingsAction.php` | Custom dashboard layout normalisation.           |
| `packages/admin/src/Contracts/DashboardSettingsContributor.php`                 | Package settings contribution contract.          |

## Next

- [Admin Dashboard Widgets](dashboard-widgets.md)
- [Customize your dashboard](dashboard-customize.md)
