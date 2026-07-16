# Dashboard Widgets: Programmatic API

![Capell Dashboard Widgets: Programmatic API screenshot](./images/screenshots/admin-dashboard.png)

Use this guide when a package or app needs to register dashboard Filament widgets, add overview stats, or adjust dashboard defaults in code. For the admin/operator view of the feature, see [Admin dashboard Filament widgets](../../../docs/admin/dashboard-widgets.md).

## Choose The API

| Need                                                   | Use                                                                                             |
| ------------------------------------------------------ | ----------------------------------------------------------------------------------------------- |
| Add a compact metric to the Capell overview            | `CapellAdmin::registerOverviewStat(...)`                                                        |
| Add a standalone Filament widget                       | `CapellAdmin::registerDashboardFilamentWidget(...)`                                             |
| Register package admin surfaces through one bridge     | `AdminBridgeRegistrar::filamentDashboardWidget(...)` or `extensionDashboardFilamentWidget(...)` |
| Add a widget to a resource or page surface             | `CapellAdmin::contributeToAdminSurface(...)` or an `AdminBridge`                                |
| Supply labels/descriptions for dashboard settings      | A class tagged with `DashboardSettingsContributor::TAG`                                         |
| Read dashboard Filament widgets for a dashboard bucket | `CapellAdmin::getDashboardFilamentWidgets($dashboard)`                                          |
| Filter legacy/core built-in widgets                    | `CapellAdmin::getWidgets(...)`                                                                  |

Prefer overview stats for small package counters. Use a standalone dashboard Filament widget for tables, charts, calendars, health panels, setup panels, or anything with its own actions.

## Dashboard Buckets

`DashboardEnum` controls where a widget can render:

```php
<?php

declare(strict_types=1);

namespace Capell\Admin\Enums;

enum DashboardEnum: string
{
    case Main = 'main';
    case MarketingStudio = 'marketing_studio';
    case Extensions = 'extensions';
    case NotInstalled = 'not_installed';
    case SystemHealth = 'system_health';
}
```

Each bucket maps to an admin surface:

| Dashboard enum                   | Surface                                                  |
| -------------------------------- | -------------------------------------------------------- |
| `DashboardEnum::Main`            | Installed admin dashboard at `/admin`.                   |
| `DashboardEnum::MarketingStudio` | Marketing Studio dashboard at `/admin/marketing-studio`. |
| `DashboardEnum::Extensions`      | Extensions dashboard at `/admin/extensions`.             |
| `DashboardEnum::SystemHealth`    | Reserved for system-health dashboard integrations.       |
| `DashboardEnum::NotInstalled`    | Setup/empty state before Admin has an installed site.    |

The main dashboard page is `Capell\Admin\Filament\Pages\CapellDashboard`.

Register against the most specific dashboard. Extension management widgets belong on `DashboardEnum::Extensions`; Marketing Studio widgets belong on `DashboardEnum::MarketingStudio`.

## Built-In Widgets

The Admin service provider and Filament plugin register the default dashboard Filament widgets. Registration decides which dashboard can use a widget. Settings decide whether a settings-gated widget is visible.

### Main Dashboard

| Widget                            | Default state | Purpose                                                         |
| --------------------------------- | ------------- | --------------------------------------------------------------- |
| `CapellAccountFilamentWidget`     | Enabled       | Filament account welcome card.                                  |
| `CapellInfoFilamentWidget`        | Enabled       | Filament version and documentation links.                       |
| `ListPagesFilamentWidget`         | Enabled       | Recently updated pages filtered by dashboard date range.        |
| `RecentActivityFilamentWidget`    | Enabled       | Recent admin activity when activity exists for the site.        |
| `UpdateAdvisoryFilamentWidget`    | Enabled       | Package/update advisory panel.                                  |
| `MyWorkQueueFilamentWidget`       | Available     | Editor work queue from the configured data provider.            |
| `RecentlyPublishedFilamentWidget` | Available     | Recently published page list from the configured data provider. |
| `PageStatusFilamentWidget`        | Available     | Page status panel.                                              |
| `SiteStatsOverviewFilamentWidget` | Available     | Site stats overview.                                            |

Resource pages can have their own alert widgets, such as page, site, blueprint, language, and theme alerts. Those widgets live on the resource screens rather than the main dashboard.

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

### Legacy Widget Enum

`FilamentWidgetEnum` is the legacy/core list used by `CapellAdmin::getWidgets()`. Each enum value is a fully-qualified widget class name.

```php
<?php

declare(strict_types=1);

namespace Capell\Admin\Enums;

enum FilamentWidgetEnum: string implements HasLabel
{
    case AccountWidget = CapellAccountFilamentWidget::class;
    case FilamentInfoWidget = CapellInfoFilamentWidget::class;
    case ListPagesFilamentWidget = ListPagesFilamentWidget::class;
    case MyWorkQueueFilamentWidget = MyWorkQueueFilamentWidget::class;
    case PageStatusFilamentWidget = PageStatusFilamentWidget::class;
    case RecentlyPublishedFilamentWidget = RecentlyPublishedFilamentWidget::class;
    case SiteStatsOverviewFilamentWidget = SiteStatsOverviewFilamentWidget::class;
    case UpdateAdvisoryFilamentWidget = UpdateAdvisoryFilamentWidget::class;
}
```

Core dashboard pages now use `registerDashboardFilamentWidget()` plus widget `settingsKey()` values for layout settings. Add package-owned widgets through a service provider or admin bridge rather than adding new enum cases.

## API Reference

| Method                                                                               | Use                                                                                                                                                    |
| ------------------------------------------------------------------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `registerOverviewStat(...)`                                                          | Registers one small stat inside the Capell overview widget. Stats can share a `settingsKey` so one toggle controls a group.                            |
| `getOverviewStats(bool $onlyEnabled = true)`                                         | Resolves registered overview stats for rendering.                                                                                                      |
| `getOverviewStatKeys()`                                                              | Returns the unique settings keys used by registered overview stats.                                                                                    |
| `registerDashboardFilamentWidget(string $widgetClass, DashboardEnum ...$dashboards)` | Registers a Filament widget class with one or more dashboard buckets.                                                                                  |
| `getDashboardFilamentWidgets(DashboardEnum $dashboard)`                              | Returns registered widget classes for that dashboard, sorted by pinned/default/configured order.                                                       |
| `getWidgets(null\|bool\|Closure $filter = null)`                                     | Returns legacy/core `FilamentWidgetEnum` widgets. Use `null` for all classes, `true` or `false` for enabled/disabled enum cases, or a callable filter. |
| `setEnabledWidgets(array<string\|FilamentWidgetEnum> $widgets)`                      | Legacy helper for replacing the enabled set used by `getWidgets()`. Prefer settings-key based dashboard settings for new package widgets.              |

`registerDashboardFilamentWidget()` only registers availability. Runtime visibility still depends on the widget's `canView()` method and, for settings-aware widgets, `AdminSettings::enabled_widgets[$settingsKey]`.

## Register Overview Stats

Overview stats are best for small counts, percentages, current status values, and links into package resources.

```php
<?php

declare(strict_types=1);

namespace Capell\Blog\Providers;

use Capell\Admin\Facades\CapellAdmin;
use Capell\Blog\Models\Article;
use Illuminate\Support\ServiceProvider;

final class AdminServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        CapellAdmin::registerOverviewStat(
            key: 'blog_overview.articles',
            label: fn (): string => __('capell-blog::dashboard.articles'),
            value: fn (): int => Article::query()->count(),
            group: fn (): string => __('capell-blog::dashboard.group'),
            description: fn (): string => __('capell-blog::dashboard.articles_description'),
            sort: 100,
            defaultEnabled: false,
            settingsKey: 'blog_overview',
            settingsLabel: fn (): string => __('capell-blog::dashboard.blog_overview'),
            settingsDescription: fn (): string => __('capell-blog::dashboard.blog_overview_description'),
        );
    }
}
```

Multiple stats can share the same `settingsKey`. The settings UI then shows one toggle while the overview widget renders every enabled stat in that group.

## Register A Custom Widget

Create a Filament widget. Implement `CapellFilamentWidgetContract` and use `GatedByRoleAndSettings` when the widget should support dashboard settings and Capell role gating.

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

Then register the widget in a service provider:

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

For extension-management widgets, use the bridge registrar when the package already has an admin bridge:

```php
$registrar->extensionDashboardFilamentWidget(PackageStatusWidget::class);
```

That registers the widget against `DashboardEnum::Extensions`.

## Settings Metadata

Dashboard settings can infer labels from widget headings, but package docs and admin screens read better when widgets expose explicit metadata.

Use `DashboardSettingsContributor` to provide the label, group, and description for a widget settings key:

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

Tag it from the package service provider:

```php
$this->app->tag([PackageDashboardSettingsContributor::class], DashboardSettingsContributor::TAG);
```

Use package-prefixed keys, for example `vendor_package.status`. If two contributors provide the same key, the later-loaded contributor wins.

## Defaults And Sync

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

Dashboard settings are normalised by `SyncDashboardFilamentWidgetSettingsAction`.

- Missing known keys are added to `enabled_widgets`.
- Keys in `AdminSettings::defaultWidgetOrder()` are treated as default-enabled.
- Package widgets that are known but not part of the default order are added as available, not force-enabled.
- Removed keys, such as retired legacy widgets, are cleaned up.
- If every known default was disabled during setup, the action can repair the default layout.

`NormalizeDashboardFilamentWidgetSettingsAction` handles submitted dashboard layout state. It updates only the keys for the dashboard being customised and preserves settings for other dashboards.

The built-in numeric settings (`my_work_queue_limit`, `recently_published_limit`, and friends) are documented on the admin [Customize your dashboard](../../../docs/admin/dashboard-customize.md#widget-limits) page.

Avoid using `setEnabledWidgets()` for new package dashboards. It stores legacy enum/class values for `getWidgets()` and does not express the settings-key layout used by the current dashboard customiser.

## Ordering

Dashboard Filament widget order is resolved in this order:

1. Widgets with negative Filament sort values stay pinned first.
2. `AdminSettings::widget_order[$settingsKey]` wins when present.
3. The widget's Filament sort value is used as the default.
4. Class name order breaks ties.

Use a stable, package-prefixed `settingsKey()` before relying on configured order. Widgets without a settings key can render, but users cannot reliably enable, disable, or reorder them through the dashboard settings UI.

## Request-Scoped Data

If `canView()` and widget hydration need the same expensive data, put that lookup behind `AdminDashboardDataRequestCache` or a package equivalent. Visibility checks run before rendering, so repeating the same queries there can make dashboards feel slow.

## Related Code

| File                                                                                                                                     | Purpose                                  |
| ---------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------- |
| [`../src/Filament/Pages/CapellDashboard.php`](../src/Filament/Pages/CapellDashboard.php)                                                 | Main dashboard page and date filter.     |
| [`../src/Support/CapellAdminManager.php`](../src/Support/CapellAdminManager.php)                                                         | Widget and overview stat registration.   |
| [`../src/Enums/DashboardEnum.php`](../src/Enums/DashboardEnum.php)                                                                       | Dashboard buckets.                       |
| [`../src/Enums/FilamentWidgetEnum.php`](../src/Enums/FilamentWidgetEnum.php)                                                             | Legacy/core built-in widget enum.        |
| [`../src/Settings/AdminSettings.php`](../src/Settings/AdminSettings.php)                                                                 | Stored dashboard settings.               |
| [`../src/Filament/Settings/Schemas/DashboardSettingsSchema.php`](../src/Filament/Settings/Schemas/DashboardSettingsSchema.php)           | Settings UI metadata aggregation.        |
| [`../src/Actions/SyncDashboardFilamentWidgetSettingsAction.php`](../src/Actions/SyncDashboardFilamentWidgetSettingsAction.php)           | Default setting repair/sync.             |
| [`../src/Actions/NormalizeDashboardFilamentWidgetSettingsAction.php`](../src/Actions/NormalizeDashboardFilamentWidgetSettingsAction.php) | Dashboard layout normalisation.          |
| [`../src/Contracts/DashboardSettingsContributor.php`](../src/Contracts/DashboardSettingsContributor.php)                                 | Settings metadata contribution contract. |

## Next

- [Admin dashboard Filament widgets](../../../docs/admin/dashboard-widgets.md)
- [Customize your dashboard](../../../docs/admin/dashboard-customize.md)
