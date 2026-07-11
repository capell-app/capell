# Marketing Studio

![Capell Marketing Studio screenshot](./images/screenshots/admin-dashboard.png)

Marketing Studio is the editor-focused dashboard at `/admin/marketing-studio`. It keeps everyday marketing work under one primary sidebar item and moves technical resources into the dashboard Advanced area.

## Registering Actions

Packages contribute links with `CapellAdmin::registerMarketingStudioAction()`:

```php
use Capell\Admin\Data\MarketingStudioActionData;
use Capell\Admin\Enums\MarketingStudioSectionEnum;
use Capell\Admin\Facades\CapellAdmin;

CapellAdmin::registerMarketingStudioAction(new MarketingStudioActionData(
    key: 'vendor-package.subscribers',
    label: fn (): string => __('vendor-package::navigation.subscribers'),
    url: fn (): string => SubscriberResource::getUrl(),
    section: MarketingStudioSectionEnum::Audience,
    icon: 'heroicon-o-envelope',
    sort: 10,
));
```

Use daily editor resources in `Campaigns`, `Audience`, `Forms`, or `Performance`. Use `Advanced` for provider connections, sync attempts, mappings, and other technical plumbing that should stay reachable without becoming sidebar noise.

## Registering Widgets

Marketing Studio widgets use the existing dashboard Filament widget system:

```php
CapellAdmin::registerDashboardFilamentWidget(
    PackagePerformanceWidget::class,
    DashboardEnum::MarketingStudio,
);
```

Widgets participate in the same enabled/order/span settings as the main and Extensions dashboards. Core widgets ship with default keys under `marketing_studio.*`.
