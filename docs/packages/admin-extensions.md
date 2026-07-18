# Admin Extensions

Packages extend the admin through `CapellAdmin`, admin bridges, tagged contracts, settings contributors, and Filament classes.

## Admin Bridges

Use an admin bridge when a package contributes more than one admin concern. The bridge keeps package wiring in one class and lets Capell boot the package's admin surface once.

```php
use Capell\Admin\Contracts\Bridges\AdminBridge;
use Capell\Admin\Data\Bridges\AdminBridgeContextData;
use Capell\Admin\Support\Bridges\AdminBridgeRegistrar;

final class ExampleAdminBridge implements AdminBridge
{
    public function register(AdminBridgeRegistrar $registrar, AdminBridgeContextData $context): void
    {
        $registrar->resource(ExampleResource::class, group: 'content', name: 'examples');
        $registrar->page(ExampleReportPage::class);
        $registrar->filamentDashboardWidget(ExampleHealthWidget::class, DashboardEnum::SystemHealth);
        $registrar->settingsClass('example', ExampleSettings::class);
        $registrar->settingsSchema('example', ExampleSettingsSchema::class);
    }
}
```

Register the bridge from the package admin provider:

```php
CapellAdmin::registerAdminBridge('capell-app/example', ExampleAdminBridge::class);
CapellAdmin::bootAdminBridges('capell-app/example');
```

For one small contribution, direct `CapellAdmin::contributeToAdminSurface(...)` calls are still acceptable. Prefer a bridge when the package adds a resource plus settings, widgets, extenders, or user menu actions.

## Pages

Register package pages through an admin bridge or directly with `AdminSurfaceContributionData::page(...)`:

```php
use Capell\Admin\Data\AdminSurfaceContributionData;

CapellAdmin::contributeToAdminSurface(
    AdminSurfaceContributionData::page(ExampleReportPage::class),
);
```

The page class should provide translated navigation labels:

```php
public static function getNavigationLabel(): string
{
    return __('capell-example::navigation.example_report');
}
```

If the page is the package's main settings or control page, register it as the extension page:

```php
CapellAdmin::registerExtensionPage('capell-app/example', ExampleSettingsPage::class);
```

This registers the Filament page and lists the package on the Extensions management page with a direct **Edit** action. Extension pages do not keep their own direct sidebar item; Capell automatically adds accessible registered extension pages to the grouped Filament sub-navigation on the Extensions page.

## Resources

Register resources when the package owns a model:

```php
use Capell\Admin\Data\AdminSurfaceContributionData;

CapellAdmin::contributeToAdminSurface(
    AdminSurfaceContributionData::resource(ExampleResource::class, group: 'content', name: 'examples'),
);
```

The `group` and `name` pair is the lookup slot other package code can use through `CapellAdmin::getResource($group, $name)`. Use a stable name instead of relying on class names when a package wants to replace or extend a known resource slot.

## Dashboard Widgets

Use dashboard slots rather than hardcoded admin registration:

```php
CapellAdmin::registerDashboardFilamentWidget(ExampleHealthWidget::class, DashboardEnum::SystemHealth);
```

Widgets should implement `Capell\Admin\Contracts\CapellFilamentWidgetContract` when they participate in Capell dashboard settings.

## User Menu Items

Use the user menu registry for admin-only package shortcuts and attention counts:

```php
CapellAdmin::registerUserMenuItem(
    key: 'capell-example.notes',
    label: fn (): string => __('capell-example::user-menu.notes'),
    url: fn (): string => route('filament.admin.pages.example-notes'),
    badge: fn (): int => ExampleNote::query()->unread()->count(),
    sort: 40,
);
```

See [User Menu Registry](../../packages/admin/docs/user-menu-registry.md) for the full API, badge rules, and translated package examples.

## Welcome Tour Steps

Packages can add onboarding steps to the Capell admin welcome tour:

```php
CapellAdmin::registerWelcomeTourStep(
    key: 'capell-example.feature',
    title: __('capell-example::welcome.feature_title'),
    description: __('capell-example::welcome.feature_description'),
    element: '.capell-example-feature',
    sort: 80,
);
```

Use stable admin-only selectors for `element`. Omit `element` for a modal step. The tour is shown on the admin dashboard and can be enabled or disabled per user from the user form.

## Dashboard Settings

Packages that add widgets should register them through `registerDashboardFilamentWidget()` and make sure Admin settings can expose their toggles. Small counters that belong inside the Capell overview widget should use `registerOverviewStat()` instead of a standalone widget.

For package settings screens, use `SettingsSchemaRegistry` directly or the bridge registrar helpers:

```php
$registrar->settingsClass('example', ExampleSettings::class);
$registrar->settingsSchema('example', ExampleSettingsSchema::class);
$registrar->settingsMetadata(new SettingsGroupMetadata(
    group: 'example',
    label: 'capell-example::settings.label',
));
```

Settings pages can extend `AbstractPackageSettingsPage` and use `SettingsGroupMetadata` for their page label, icon, and sort.

## Form And Table Extenders

Use tagged extenders when adding fields or actions to core resources:

```php
$this->app->tag([ExamplePageSchemaExtender::class], PageSchemaExtender::TAG);
```

Common tags:

| Need                                                            | Tag or registry                       |
| --------------------------------------------------------------- | ------------------------------------- |
| Page form fields, tabs, sidebar components, relation managers   | `PageSchemaExtender::TAG`             |
| Site form fields, tabs, create wizard fields, relation managers | `SiteSchemaExtender::TAG`             |
| Layout tabs and relation managers                               | `LayoutSchemaExtender::TAG`           |
| User fields, lifecycle, relation managers, and table additions  | `UserResourceBridge::TAG`             |
| Page table columns, filters, bulk actions, query changes        | `PageTableExtender::TAG`              |
| Page header actions                                             | `PageHeaderActionExtender::TAG`       |
| Site header actions                                             | `SiteHeaderActionExtender::TAG`       |
| Site table row actions                                          | `SiteRecordActionExtender::TAG`       |
| Header actions on arbitrary resource pages                      | `ResourceHeaderActionExtender::TAG`   |
| Page title/slug field actions or after-label schema             | `PageTitleWithSlugInputExtender::TAG` |
| Page edit form actions or header widgets                        | `PageEditExtender::TAG`               |
| Page/site export modal fields and options                       | `PageExportExtender::TAG`             |
| Publish panel sections                                          | `PublishPanelExtender::TAG`           |
| Media edit header actions                                       | `MediaEditActionExtender::TAG`        |
| Extensions page status content                                  | `ExtensionsPageExtender::TAG`         |
| Filament panel configuration                                    | `AdminPanelExtender::TAG`             |
| Admin header tools                                              | `AdminToolItem::TAG`                  |

Prefer extenders over modifying admin resources directly.

Use the abstract schema extenders when possible:

- `AbstractPageSchemaExtender`
- `AbstractSiteSchemaExtender`
- `AbstractUserResourceBridge`

They provide no-op defaults so a package only overrides the hooks it needs. See [Schema Hooks](../../packages/admin/docs/schemas/hooks.md) for method signatures, hook enums, and resolver debugging.

## Configurators

Configurators shape an existing Capell editing surface: blueprints, pages, sites, languages, layouts, and themes. A configurator implements `Capell\Admin\Contracts\ConfiguratorInterface` with a stable `getKey()`, a `getSort()` order, and a `configure()` method that returns a Filament `Schema`.

Register configurators from an admin bridge:

```php
use Capell\Admin\Enums\ConfiguratorTypeEnum;

$registrar->configurator(
    ExampleSiteConfigurator::class,
    group: ConfiguratorTypeEnum::Site->value,
    name: ExampleSiteConfigurator::getKey(),
);
```

Keep configurators thin: assemble Filament components there, put domain work in Actions, and keep visible text in translations. Wrap normal fields in contained `Section` components, the same presentation rule as settings schemas. Use package settings schemas for package-level configuration; use configurators only to extend an existing editing surface.

## Page Table Status

The main Pages table renders one core publish/workflow status column. Admin owns the column layout; packages that provide workflow semantics should replace the resolver instead of adding a competing status column.

Bind `Capell\Admin\Contracts\Pages\PageTableStatusResolver` from the package admin provider:

```php
use Capell\Admin\Contracts\Pages\PageTableStatusResolver;
use Capell\Admin\Data\Pages\PageTableStatusData;
use Capell\Core\Models\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;

$this->app->singleton(PageTableStatusResolver::class, ExampleWorkflowPageStatusResolver::class);

final class ExampleWorkflowPageStatusResolver implements PageTableStatusResolver
{
    /**
     * @param  Builder<Page>  $query
     * @return Builder<Page>
     */
    public function modifyQuery(Builder $query): Builder
    {
        return $query->with('latestWorkflowStep');
    }

    public function resolve(Page $page): PageTableStatusData
    {
        return new PageTableStatusData(
            label: __('capell-example::workflow.awaiting_review'),
            shortLabel: __('capell-example::workflow.review_short'),
            tooltip: __('capell-example::workflow.awaiting_review_tooltip'),
            color: 'info',
            icon: Heroicon::OutlinedClipboardDocumentCheck,
        );
    }
}
```

Core falls back to publish-date states: deleted, expired, scheduled, and published. Approval, draft, rollback, and workspace states belong in workflow packages through this resolver.

## Extensions Dashboard

`/admin/extensions` is the Extensions dashboard. Keep package management pages in the Filament left sub-navigation by registering them with `CapellAdmin::registerExtensionPage($packageName, PageClass::class)`.

Use dashboard Filament widgets for operational package status, health checks, shortcuts, and marketplace-style actions that belong on the overview. Register widgets against the Extensions dashboard scope:

```php
use Capell\Admin\Enums\DashboardEnum;
use Capell\Admin\Facades\CapellAdmin;

CapellAdmin::registerDashboardFilamentWidget(
    ExampleExtensionHealthFilamentWidget::class,
    DashboardEnum::Extensions,
);
```

Admin bridges can use the convenience method:

```php
$registrar->extensionDashboardFilamentWidget(ExampleExtensionHealthFilamentWidget::class);
```

Every extension dashboard Filament widget must use a globally unique `settingsKey()`, preferably package-prefixed, so dashboard customisation can enable, disable, reorder, and resize it without colliding with core widgets.

Extension dashboard Filament widgets may implement `Capell\Admin\Contracts\Extensions\ExtensionFilamentDashboardWidgetContract` when they want to expose their package-author metadata explicitly. The contract defines the widget settings key, label, description, default span, default order, dashboard scope, and `canView()` gate. Existing `CapellFilamentWidgetContract` widgets remain supported; the contract is for packages that want their dashboard contribution to be self-describing.

Packages can also contribute operation data without coupling to the Filament UI. Register providers from an admin bridge:

```php
$registrar->extensionHealthProvider(ExampleHealthProvider::class);
$registrar->extensionRuntimeCheckProvider(ExampleRuntimeProvider::class);
$registrar->extensionQuickActionProvider(ExampleQuickActionProvider::class);
$registrar->extensionUpdateMetadataProvider(ExampleUpdateProvider::class);
$registrar->extensionDependencyProvider(ExampleDependencyProvider::class);
```

Provider contracts live in `Capell\Admin\Contracts\Extensions`:

| Contract                          | Use                                                                                                  |
| --------------------------------- | ---------------------------------------------------------------------------------------------------- |
| `ExtensionHealthProvider`         | Adds package health alerts to the diagnostics surface.                                               |
| `ExtensionRuntimeCheckProvider`   | Adds runtime compatibility checks such as queues, cache stores, services, or package-specific gates. |
| `ExtensionQuickActionProvider`    | Adds safe package-level operational shortcuts. Keep destructive work permission-gated.               |
| `ExtensionUpdateMetadataProvider` | Supplies update readiness states when metadata comes from a package or marketplace integration.      |
| `ExtensionDependencyProvider`     | Adds uninstall, disable, or update blockers beyond Capell core package protection.                   |

Core catches health provider failures and records a warning diagnostic for the affected package instead of breaking the dashboard. Marketplace integration remains optional; core widgets work from local manifests, installed package data, extension records, runtime gates, and health alerts.

## Extensions Page Actions

Use `Capell\Admin\Support\Extensions\ExtensionsPageActionRegistry` when a package needs to add a command to the Extensions page.

Register header actions for package-level work such as installing example content, syncing metadata, or opening a setup flow. Register table actions only when the action belongs to a specific extension row.

The Extensions page already provides core row actions such as documentation and uninstall where available, so packages should not duplicate those actions.

See [Build an extension end to end](build-extension-end-to-end.md) for the full package authoring flow and code examples.

## Extensions Page Contributions

Optional packages should extend the core Extensions page instead of registering a competing package manager page.

Use `ExtensionsPageActionRegistry` for header actions that open modal workflows:

```php
resolve(ExtensionsPageActionRegistry::class)->registerHeaderAction(
    fn (ExtensionsPage $page): Action => Action::make('examplePackageAction')
        ->label(__('capell-example::actions.example'))
        ->modalContent(view('capell-example::filament.extensions.example-modal')),
);
```

Gate package-management work with `->authorize(fn (): bool => ExtensionsPage::canManageExtensions())`.

Use `registerTableAction()` when the action belongs to one extension row. Table action callbacks receive the row as an array:

```php
resolve(ExtensionsPageActionRegistry::class)->registerTableAction(
    fn (ExtensionsPage $page): Action => Action::make('checkExtensionHealth')
        ->label(__('capell-example::actions.check_health'))
        ->action(fn (array $record): mixed => CheckExtensionHealthAction::run($record['name'])),
);
```

Use `ExtensionsPageExtender::TAG` for status alerts or explanatory blocks shown in the Extensions dashboard actions area. Keep package-specific business logic inside the package contributor. New operational surfaces should prefer an Extensions dashboard Filament widget.

Marketplace connection UI should stay action-oriented:

- When the site is not connected, show the connection state and the **Connect Capell account** action.
- When the site is connected, do not render a success alert above the table. Show **Open Marketplace** as the primary header action.
- **Open Marketplace** opens the marketplace browser in a Filament modal from `/admin/extensions`, keeping installed extensions and marketplace browsing in one workflow.
- Do not use a large success alert for the connected state. The account link is a means to access Marketplace, not the destination.
- Marketplace catalogue API calls must request JSON and use a sort value the Capell app API supports. The safe default is `recommended`; Capell app also accepts the browser sort values `featured_latest`, `latest`, `price_low`, `price_high`, and `name`.

## Navigation

Use Filament page methods for labels, icons, groups, and sort order. Store labels in package translation files.

If a package uses a custom parent navigation group from `getNavigationGroup()`, register that group from the package admin provider:

```php
use Capell\Admin\Enums\NavigationGroupPositionEnum;
use Capell\Admin\Facades\CapellAdmin;
use Filament\Support\Icons\Heroicon;

CapellAdmin::registerNavigationGroup(
    label: 'capell-example::navigation.example',
    icon: Heroicon::OutlinedSquares2X2,
    position: NavigationGroupPositionEnum::After,
    relativeTo: 'capell-admin::navigation.group_content',
);
```

Capell resolves translated labels before merging, so multiple packages can register the same group independently without creating duplicate sidebar groups. Position can be `Start`, `End`, `Before`, or `After`; `Before` and `After` require `relativeTo`.

## Debugging Admin Extensions

If an admin contribution is missing, start with [Extension Troubleshooting](extension-troubleshooting.md). The common fixes are:

- confirm the package admin provider is loaded;
- confirm the bridge was registered and booted for the package name;
- run `php artisan capell:admin-clear-cache`;
- run `php artisan capell:admin-cache-configurators` for configurators and schema extenders;
- run `php artisan capell:admin-cache-widgets` for widgets;
- re-run `php artisan capell:admin-install` when permissions or policies changed.
