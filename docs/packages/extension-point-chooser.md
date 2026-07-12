# Extension Point Chooser

Use this page before adding a hook, service provider call, or package integration. Start with the table that matches the runtime you are changing, then follow the linked page for the full contract and examples.

If you are not sure which runtime you are changing, start with [Package authoring jobs](package-authoring-jobs.md) and [Extension surface vocabulary](extension-surface-vocabulary.md). Those pages define the package surfaces and install-impact terms used by this chooser.

## Core And Package Runtime

| Need                                   | Use                                                                                       | Owner                 |
| -------------------------------------- | ----------------------------------------------------------------------------------------- | --------------------- |
| Register a page subject type           | `CapellCore::registerPageType(new PageTypeData(...))`                                     | Core                  |
| Replace a core model implementation    | Laravel container binding for the model class                                             | Core                  |
| Register renderable definitions        | `RenderableRegistry::register(...)`                                                       | Core                  |
| Register link picker/search options    | `LinkableContentRegistry::register(...)`                                                  | Core                  |
| Register content graph extractors      | `ContentGraphRegistry::register(...)` or `ContentGraphRegistry::TAG`                      | Core                  |
| Add package settings                   | `SettingsSchemaRegistry::register()`, `registerSettingsClass()`, and `registerMetadata()` | Core/Admin            |
| Add package settings migrations        | `database/settings/*` plus package install/setup registration                             | Core                  |
| Add developer-tooling makers           | `MakerRegistryInterface::register(...)`                                                   | Core                  |
| Load vendor build assets conditionally | `VendorAssetConditionRegistry::register(...)`                                             | Core/Frontend         |
| Add static export files                | `StaticSiteExtensionRegistry::register(...)`                                              | Static export package |
| Extend ownership/export mapping        | `OwnershipMap::register(...)`                                                             | Migration Assistant   |

## Admin Runtime

| Need                                                                                                         | Use                                                                                                                 | Owner |
| ------------------------------------------------------------------------------------------------------------ | ------------------------------------------------------------------------------------------------------------------- | ----- |
| Add admin resources, pages, widgets, configurators, user menu items, settings, or extenders from one package | `AdminBridge` and `AdminBridgeRegistrar`                                                                            | Admin |
| Add one small admin surface                                                                                  | `CapellAdmin::contributeToAdminSurface(AdminSurfaceContributionData::...)`                                          | Admin |
| Register a package settings/control page on Extensions                                                       | `CapellAdmin::registerExtensionPage(...)`                                                                           | Admin |
| Add dashboard Filament widgets                                                                               | `CapellAdmin::registerDashboardFilamentWidget(...)`                                                                 | Admin |
| Add small dashboard metrics                                                                                  | `CapellAdmin::registerOverviewStat(...)`                                                                            | Admin |
| Replace the dashboard page                                                                                   | `AdminBridgeRegistrar::dashboardPage(...)` or `CapellAdmin::useDashboardPage(...)`                                  | Admin |
| Add user menu actions                                                                                        | `CapellAdmin::registerUserMenuItem(...)`                                                                            | Admin |
| Add admin header tools                                                                                       | Tag an `AdminToolItem` with `AdminToolItem::TAG`                                                                    | Admin |
| Add welcome tour steps                                                                                       | `CapellAdmin::registerWelcomeTourStep(...)`                                                                         | Admin |
| Add navigation groups                                                                                        | `CapellAdmin::registerNavigationGroup(...)`                                                                         | Admin |
| Add content widgets                                                                                          | `CapellAdmin::registerWidget(...)` or `registerDiscoverableWidgets(...)`                                            | Admin |
| Add fields to page, site, layout, or user forms                                                              | Tagged schema extenders such as `PageSchemaExtender::TAG`                                                           | Admin |
| Add page/site/resource header actions                                                                        | Tagged action extenders such as `PageHeaderActionExtender::TAG`                                                     | Admin |
| Modify page, user, or resource tables                                                                        | Tagged table extenders such as `PageTableExtender::TAG`                                                             | Admin |
| Extend the installed Extensions page                                                                         | `ExtensionsPageActionRegistry` or `ExtensionsPageExtender::TAG`                                                     | Admin |
| Subscribe to admin serving events                                                                            | `CapellAdmin::serving(...)`                                                                                         | Admin |
| Register activity resource links/display/revert behavior                                                     | `CapellAdmin::registerActivityResourceLink(...)`, `ActivityChangeSetBuilder::TAG`, and `ActivityRevertHandler::TAG` | Admin |

## Frontend Runtime

| Need                                     | Use                                                                      | Owner         |
| ---------------------------------------- | ------------------------------------------------------------------------ | ------------- |
| Inject small public HTML                 | `RenderHookRegistry::register(...)`                                      | Frontend      |
| Add public widgets                       | `LayoutWidgetRegistry::register(...)` with a frontend target             | Frontend/Core |
| Register frontend component aliases      | `FrontendComponentRegistry::register(...)`                               | Frontend      |
| Register package CSS or JS sources       | `TailwindAssetsRegistry::registerSource()` / `registerImport()`          | Core/Frontend |
| Register runtime frontend assets         | `FrontendResourceRegistry` groups / `FrontendAssetContributor::TAG`      | Frontend      |
| Reserve package-owned public paths       | `ReservedFrontendPathRegistry::reserveExact()` / `reservePrefix()`       | Frontend      |
| Add middleware to the public page route  | `FrontendRouteMiddlewareRegistry`                                        | Frontend      |
| Add frontend rule conditions             | `FrontendRuleConditionRegistry::register(...)`                           | Frontend      |
| Replace response rendering for a runtime | `FrontendResponseRendererRegistry::register(...)` / `registerClass(...)` | Frontend      |
| Invalidate pages when a model changes    | `CacheInvalidationRegistry::registerDependency(...)`                     | Frontend      |

## Rules

- Prefer an existing extension point over patching host package classes.
- Keep package feature logic in the package. Host packages should expose contracts, not product behavior.
- Put writes in Actions and structured state in Data objects.
- Keep visible strings in translations.
- Public output must pass the [public HTML safety contract](../frontend/public-html-safety.md).
- Register most extension points from a package provider's `boot()` method unless the contract explicitly says to bind something in `register()`.

## Next

- [Package authoring](README.md)
- [Package authoring jobs](package-authoring-jobs.md)
- [Extension surface vocabulary](extension-surface-vocabulary.md)
- [Extension point API reference](extension-point-api-reference.md)
- [Admin extensions](admin-extensions.md)
- [Frontend extensions](frontend-extensions.md)
- [Extension troubleshooting](extension-troubleshooting.md)
