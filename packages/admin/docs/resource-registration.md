# Resource Contributions

![Capell Resource Contributions screenshot](./images/screenshots/admin-dashboard.png)

Capell's admin panel uses admin surface contributions to expose resources, pages, widgets, panel extenders, and related admin UI additions through one registry.

## Resources

Resources are Filament resource classes that provide admin panel functionality for a given model type. Capell uses resource contributions to:

- Link media to models.
- Navigate from dashboards and reports to resource pages.
- Let add-on packages expose admin screens without patching the panel directly.

## Resource Groups And Names

Resources are grouped by model or admin concept, with an optional name for variants.

- Group: the primary model or surface, such as `Page`, `User`, or `Article`.
- Name: the variant inside that group, defaulting to `default`.

Article pages can be registered under the `Page` group with the `article` name so Page-based features can still find the specialized article resource.

## Registering Resources

```php
use App\Filament\Resources\CustomResource;
use Capell\Admin\Data\AdminSurfaceContributionData;
use Capell\Admin\Facades\CapellAdmin;

public function boot(): void
{
    CapellAdmin::contributeToAdminSurface(
        AdminSurfaceContributionData::resource(CustomResource::class, group: 'Custom'),
    );
}
```

For a Page variant:

```php
use App\Filament\Resources\ArticleResource;
use Capell\Admin\Data\AdminSurfaceContributionData;
use Capell\Admin\Enums\ResourceEnum as AdminResourceEnum;
use Capell\Admin\Facades\CapellAdmin;

public function boot(): void
{
    CapellAdmin::contributeToAdminSurface(
        AdminSurfaceContributionData::resource(
            ArticleResource::class,
            group: AdminResourceEnum::Page->name,
            name: 'article',
        ),
    );
}
```

## Looking Up Resources

Use `AdminSurfaceLookup` for runtime lookups.

```php
use Capell\Admin\Support\AdminSurfaceLookup;

$resourceClass = AdminSurfaceLookup::resourceIfRegistered('Article');

if ($resourceClass !== null) {
    $url = $resourceClass::getUrl('edit', ['record' => $articleId]);
}
```

For required resources, use `AdminSurfaceLookup::resource($group, $name)`. It throws when the resource is not registered.

## Inspecting Contributions

```php
use Capell\Admin\Facades\CapellAdmin;

$resources = CapellAdmin::getAdminSurfaceRegistry()->resources();
$pageResources = CapellAdmin::getAdminSurfaceRegistry()->resourcesForGroup('Page');
$pages = CapellAdmin::getAdminSurfaceRegistry()->pages();
```

## Add-on Package Resources

Add-on packages should contribute admin resources from their service providers once the package is installed.

```php
use Capell\Admin\Data\AdminSurfaceContributionData;
use Capell\Admin\Facades\CapellAdmin;
use Capell\Blog\Filament\Resources\Articles\ArticleResource;

public function boot(): void
{
    CapellAdmin::contributeToAdminSurface(
        AdminSurfaceContributionData::resource(ArticleResource::class, group: 'Article'),
    );
}
```

The Media library gracefully handles missing resources. Owners with no registered resource display as plain text instead of a link.

## Package-Owned Widgets

Admin exposes extension points for package widgets without hard-coding package product behavior. For example, Pages-list SEO totals and per-page SEO audit panels belong to `capell-app/seo-suite`, not `capell-app/admin`.

`PageResource::getWidgets()` exposes `PageResourceWidgetExtender` so installed packages can add Pages-list widgets. `EditPage::afterSave()` dispatches the `refresh-seo-audit` browser event without depending on an SEO package class, so SEO Suite can refresh its edit-page widget when installed.

Keep this boundary when adding package widgets: Admin owns the hook; the package owns the feature.

## Page View Actions

The page edit header owns a grouped `View page` action. Core always adds the saved public URL inside that group as `Open published page` by reusing `VisitUrlAction`; packages can contribute draft or preview variants through `PagePreviewActionExtender::TAG`, usually labelled `Preview changes`. Package actions append beside the core action without replacing it.

Use this hook only for page edit preview variants. Table and list actions should keep using `VisitUrlAction` or the existing resource-header extension points.
