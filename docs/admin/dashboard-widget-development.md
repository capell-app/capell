# Register a dashboard Filament widget


Capell Admin dashboards are built from Filament widgets: product status, content activity, editor work queues, extension health, and marketing operations. Package developers register widgets in code. Admins choose which registered widgets appear from [Customize your dashboard](dashboard-customize.md).

Build a dashboard widget when a package needs its own table, chart, health panel, calendar, or actions on a dashboard. For a small count, status, or link, register an overview stat instead.

## Steps

1. Create a Filament widget that implements `Capell\Admin\Contracts\CapellFilamentWidgetContract`, uses the `GatedByRoleAndSettings` concern, and sets a package-prefixed settings key such as `vendor_package.status`.
2. Register it from the package service provider with `CapellAdmin::registerDashboardFilamentWidget(...)` against a `DashboardEnum` bucket, or through an admin bridge.
3. Optionally tag a `DashboardSettingsContributor` to set the widget's label, group, and description in dashboard settings.

Registration makes the widget available to a dashboard bucket. The widget renders only when `canView()` passes and its settings key is enabled.

The full API — dashboard buckets, built-in widgets, overview stats, settings sync, and ordering — lives in the canonical package reference:

**[Dashboard Widgets: Programmatic API](../../packages/admin/docs/dashboard-widget-customization.md)**

## Related Tasks

| Task                                          | Read                                                               |
| --------------------------------------------- | ------------------------------------------------------------------ |
| Choose which widgets appear and their order   | [Customize your dashboard](dashboard-customize.md)                 |
| Add widgets to resource or page surfaces      | [Admin extensions](../packages/admin-extensions.md)                |
| Add a frontend Layout Builder widget          | [Frontend widget registration](../frontend/widget-registration.md) |

## Next

- [Dashboard Widgets: Programmatic API](../../packages/admin/docs/dashboard-widget-customization.md)
- [Admin Dashboard Widgets](dashboard-widgets.md)
