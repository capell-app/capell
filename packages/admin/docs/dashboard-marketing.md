# Marketing dashboard contributions

Marketing actions and widgets appear on the main Dashboard. Packages retain their existing contribution APIs; the sidebar uses Content Library and Design for reusable content and page-building tools.

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

Marketing widgets use the existing dashboard Filament widget system:

```php
CapellAdmin::registerDashboardFilamentWidget(
    PackagePerformanceWidget::class,
    DashboardEnum::MarketingStudio,
);
```

Widgets participate in the same enabled/order/span settings as other dashboard widgets. Core widgets ship with default keys under `marketing_studio.*`.
