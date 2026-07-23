# Extension Point API Reference

Use this page when you know what you want to extend and need the exact contract, registration point, and fallback behavior. Start with [Extension point chooser](extension-point-chooser.md) if you are still deciding.

Registration uses two complementary mechanisms: contract tags for focused contributors, and `AdminBridgeRegistrar` for grouped admin integration. `AdminBridgeRegistry` is the internal read side, not a package-authoring write path. See [Package provider conventions](../development/package-provider-conventions.md) before adding provider wiring or a new registry.

Machine-readable stability and ownership are defined by the [extension surface catalogue](extension-surface-catalog.md). Use its stable IDs in compatibility decisions and package contract failures.

## Reading The Tables

| Column        | Meaning                                                                      |
| ------------- | ---------------------------------------------------------------------------- |
| API           | Contract, registry, facade, or tag to use.                                   |
| Register from | Where package code should usually register it.                               |
| Called by     | Runtime surface that consumes the contribution.                              |
| Safe fallback | What should happen when the package is absent, disabled, or returns nothing. |
| Test recipe   | Smallest useful proof.                                                       |

## Core And Package Runtime

| Need                     | API                                                             | Register from                                          | Called by                                                     | Safe fallback                         | Test recipe                                                                                                                                              |
| ------------------------ | --------------------------------------------------------------- | ------------------------------------------------------ | ------------------------------------------------------------- | ------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Page subject type        | `CapellCore::registerPageType(new PageTypeData(...))`           | Runtime provider `registeringPackage()` or boot method | Blueprint/page type selection                                 | Type is absent from selectors         | Assert `CapellCore::getPageTypes()` contains the key.                                                                                                    |
| Model morph alias        | `CapellCore::registerModels()`                                  | Runtime provider                                       | Morph map and model resolution                                | Laravel default morph behavior        | Assert morph map resolves the model alias.                                                                                                               |
| Model behavior           | `CapellCore::registerModelInterceptor()`                        | Runtime/admin provider                                 | Capell model interceptor resolver                             | Base model behavior remains unchanged | Instantiate model and assert interceptor method affects only matching context.                                                                           |
| Settings schema          | Provider `surface()` or `AdminBridgeRegistrar`                  | Runtime/admin provider or AdminBridge                  | Admin settings surfaces                                       | Group/tab is absent                   | Resolve registry and assert schema/settings class for group.                                                                                             |
| Package settings page    | `CapellAdmin::registerExtensionPage()`                          | AdminBridge or admin provider                          | Installed Extensions page and grouped Filament sub-navigation | No package control page               | Assert `ExtensionPageRegistry::get($packageName)` returns the page and the sub-navigation contains accessible registered pages grouped by product group. |
| Content graph extraction | `ContentGraphRegistry::TAG` or registry registration            | Runtime provider                                       | Content graph builders                                        | Content is not linked into graph      | Build graph for fixture content and assert edge exists.                                                                                                  |
| Link picker entries      | `LinkableContentRegistry::register(...)`                        | Runtime/admin provider                                 | Link picker/search UI                                         | Item type is not searchable           | Assert registry includes provider and search returns fixture.                                                                                            |
| Renderable definition    | `RenderableRegistry::register(...)`                             | Runtime provider                                       | Rendering/runtime builders                                    | Definition is unavailable             | Assert registry returns the definition key and any `viewDataResolver` returns explicit view variables.                                                   |
| Tailwind assets          | `TailwindAssetsRegistry::registerSource()` / `registerImport()` | Runtime/frontend provider                              | Frontend/admin asset commands                                 | Package classes are not scanned       | Assert `toReport()` includes source/import origin.                                                                                                       |
| Vendor asset condition   | `VendorAssetConditionRegistry::register(...)`                   | Runtime provider                                       | Asset manifest builders                                       | Asset is not conditionally loaded     | Assert condition returns expected value for fixture context.                                                                                             |
| Developer maker          | `MakerRegistryInterface::register(...)`                         | Runtime/dev provider                                   | Maker commands                                                | Maker command option is absent        | Assert maker appears in registry.                                                                                                                        |

## Admin Runtime

| Need                                   | API                                                                                                                               | Register from                      | Called by                          | Safe fallback                         | Test recipe                                                   |
| -------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------- | ---------------------------------- | ------------------------------------- | ------------------------------------------------------------- |
| Multiple admin surfaces                | `AdminBridge` with `AdminBridgeRegistrar`                                                                                         | Runtime/admin provider             | Admin bridge registry              | Package contributes no admin surfaces | Assert bridge is registered for package name.                 |
| Single admin page/resource/widget      | `CapellAdmin::contributeToAdminSurface(AdminSurfaceContributionData::...)`                                                        | Admin provider                     | `CapellAdminPlugin`                | Surface is absent                     | Assert admin surface lookup contains the class.               |
| Dashboard Filament widget              | `CapellAdmin::registerDashboardFilamentWidget(...)`                                                                               | Admin provider or bridge           | Dashboard Filament widget resolver | Widget hidden                         | Assert widget appears in expected dashboard slot.             |
| Overview stat                          | `CapellAdmin::registerOverviewStat(...)`                                                                                          | Admin provider                     | Dashboard stats                    | Stat hidden                           | Assert stat key appears for allowed user/context.             |
| User menu item                         | `CapellAdmin::registerUserMenuItem(...)`                                                                                          | Admin provider                     | User menu registry                 | Menu item hidden                      | Assert user menu registry contains item.                      |
| Admin header tool                      | `AdminToolItem::TAG`                                                                                                              | Tag class in provider              | Admin tool registry                | Tool hidden                           | Resolve `app()->tagged(AdminToolItem::TAG)`.                  |
| Page form fields                       | `PageSchemaExtender::TAG`                                                                                                         | Tag extender in provider or bridge | Page schema resolver               | Base page form only                   | Build schema for fixture page and assert field exists.        |
| Site form fields                       | `SiteSchemaExtender::TAG`                                                                                                         | Tag extender in provider or bridge | Site schema resolver               | Base site form only                   | Build schema for fixture site and assert field exists.        |
| Layout form fields                     | `LayoutSchemaExtender::TAG`                                                                                                       | Tag extender in provider or bridge | Layout schema resolver             | Base layout form only                 | Build schema for fixture layout and assert field exists.      |
| User resource contributions            | `UserResourceBridge::TAG`                                                                                                         | Register through admin bridge      | User resource bridge resolver      | Base user resource only               | Assert context, lifecycle, and visible contribution behavior. |
| Page table query                       | `PageTableExtender::TAG`                                                                                                          | Tag extender in provider           | Page tables and page selects       | Base query                            | Assert modified query includes expected relation/filter.      |
| Page table publish/workflow status     | `PageTableStatusResolver` container binding                                                                                       | Admin provider                     | Pages table status column          | Publish-date status resolver          | Bind a fake resolver and assert the table renders its state.  |
| Header actions                         | `PageHeaderActionExtender::TAG`, `SiteHeaderActionExtender::TAG`, `ResourceHeaderActionExtender::TAG`                             | Tag extender in provider           | Resource/page action resolvers     | Action hidden                         | Render header actions and assert action key.                  |
| Site row actions                       | `SiteRecordActionExtender::TAG`                                                                                                   | Tag extender in provider           | Sites table                        | Action hidden                         | Render table actions for fixture site.                        |
| Publish panel content                  | `PublishPanelExtender::TAG`                                                                                                       | Tag extender in provider           | Page publish section               | Panel remains unchanged               | Assert view/html appears only for supported page.             |
| Page edit content                      | `PageEditExtender::TAG`                                                                                                           | Tag extender in provider           | Edit page                          | Extra content hidden                  | Render edit page and assert component appears.                |
| Page resource pages/widgets            | `PageResourcePageExtender::TAG`, `PageResourceWidgetExtender::TAG`                                                                | Tag extender in provider           | Page resource                      | Extra pages/widgets hidden            | Assert `PageResource` includes class.                         |
| Page export fields                     | `PageExportExtender::TAG`                                                                                                         | Tag extender in provider           | Page/site export actions           | Extra fields omitted                  | Run export action and assert payload contains field.          |
| Media edit actions                     | `MediaEditActionExtender::TAG`                                                                                                    | Tag extender in provider           | Media edit page                    | Action hidden                         | Render media edit actions.                                    |
| Extensions page content                | `ExtensionsPageExtender::TAG` or `AdminBridgeRegistrar::extensionsPageExtender()`                                                 | Admin bridge or provider           | Installed Extensions page          | No package alert/content              | Render Extensions page and assert package section.            |
| Extensions page actions                | `AdminBridgeRegistrar::extensionsPageHeaderAction()`, `extensionsPageHeaderActionGroupAction()`, or `extensionsPageTableAction()` | Admin bridge                       | Installed Extensions page          | Action hidden                         | Resolve registry and assert header/table action key.          |
| Admin panel customization              | `AdminPanelExtender::TAG`                                                                                                         | Tag extender in provider           | `CapellAdminPlugin`                | Base panel config                     | Build panel and assert plugin/middleware/theme change.        |
| Validation gates                       | `ValidationSubscriber` or subscriber contracts                                                                                    | Runtime/admin provider             | Subscriber manager/admin events    | Validation not applied                | Notify event with fixture and assert error/result.            |
| Admin event handlers                   | `AdminEventRegistry::register(...)`                                                                                               | Admin provider                     | `HasDynamicEventListeners`         | Event has no extra handler            | Dispatch Livewire event and assert handler ran.               |
| Activity resource links/display/revert | `CapellAdmin::registerActivityResourceLink(...)`, `ActivityChangeSetBuilder::TAG`, `ActivityRevertHandler::TAG`                   | Admin provider                     | Activity table/revert actions      | Default activity behavior             | Run activity link builder/revert fixture.                     |
| Site Health report                     | `SiteHealthReportExtender::TAG`                                                                                                   | Admin provider                     | Site Health action/page            | Report section absent                 | Build Site Health report and assert section.                  |

## Frontend Runtime

| Need                          | API                                                                    | Register from                            | Called by                         | Safe fallback                        | Test recipe                                                        |
| ----------------------------- | ---------------------------------------------------------------------- | ---------------------------------------- | --------------------------------- | ------------------------------------ | ------------------------------------------------------------------ |
| Small public HTML injection   | `RenderHookRegistry::register(...)`                                    | Frontend/runtime provider                | Public Blade hook calls           | Hook outputs nothing                 | Render anonymous page and assert safe HTML.                        |
| Frontend component alias      | `FrontendComponentRegistryInterface::register(...)`                    | Frontend provider                        | Runtime component resolver        | Alias is unavailable                 | Assert `has()` and `hasReference()` for key/alias.                 |
| Builder block rendering       | `LayoutWidgetRegistry::register(...)` with frontend target             | Runtime/frontend provider                | Content renderer                  | Block is skipped/unrenderable        | Render fixture block and assert output.                            |
| Reserved public path          | `ReservedFrontendPathRegistry::reserveExact()` / `reservePrefix()`     | Frontend provider before fallback routes | Frontend route resolver           | Path falls through to page lookup    | Assert package route responds and page fallback does not catch it. |
| Public route middleware       | `FrontendRouteMiddlewareRegistry`                                      | Frontend provider                        | Public page route                 | Base middleware order                | Assert `all()` order or request side effect.                       |
| Frontend rule condition       | `FrontendRuleConditionRegistry::register(...)`                         | Frontend provider                        | Runtime rules                     | Rule never matches                   | Directly evaluate condition against fixture context.               |
| Response renderer             | `FrontendResponseRendererRegistry::register()` / `registerClass()`     | Frontend provider                        | Runtime response pipeline         | Default renderer                     | Resolve renderer for runtime and assert response class/content.    |
| Cache invalidation            | `CacheInvalidationRegistry::registerDependency(...)`                   | Frontend/runtime provider                | Model observers/cache invalidator | Cache remains until TTL/manual clear | Save fixture model and assert invalidation plan.                   |
| Runtime frontend assets       | `FrontendResourceRegistry` groups / `FrontendResourceContributor::TAG` | Frontend provider                        | Frontend resource-plan builder    | Asset not emitted                    | Build resource plan and assert resource handle/url.                |
| Runtime manifest contributors | `FrontendRuntimeManifestContributor::TAG`                              | Frontend provider                        | Frontend runtime builder          | Manifest lacks package data          | Build runtime manifest and assert contribution.                    |
| Resource contributors         | `FrontendResourceContributor::TAG`                                     | Frontend provider                        | Frontend resource-plan builder    | Asset absent                         | Build resource plan and assert package handle.                     |

## Installer And Marketplace Runtime

| Need                                     | API                                                                                                                                           | Register from                          | Called by                         | Safe fallback                 | Test recipe                                                                   |
| ---------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------- | -------------------------------------- | --------------------------------- | ----------------------------- | ----------------------------------------------------------------------------- |
| Browser install guide patch              | `Patch` plus `PatchRegistry::register(...)`                                                                                                   | Installer provider `registerPatches()` | Install guide page/action         | Patch absent                  | Probe/apply fixture host file and assert idempotence.                         |
| Installer default packages               | `CAPELL_SETUP_DEFAULT_PACKAGES` plus package metadata                                                                                         | Host env/config                        | Browser installer page data       | Package not preselected       | Build installer page data and assert default list.                            |
| Extension dashboard Filament widget      | `CapellAdmin::registerDashboardFilamentWidget($widget, DashboardEnum::Extensions)` or `$registrar->extensionDashboardFilamentWidget($widget)` | Admin provider or admin bridge         | Extensions dashboard              | Widget absent                 | Register fixture widget and assert it appears in `DashboardEnum::Extensions`. |
| Marketplace installed extensions content | `AdminBridgeRegistrar::extensionsPageExtender()`                                                                                              | Marketplace admin bridge               | Extensions dashboard actions area | No Marketplace alert          | Render Extensions page in connected/unconnected states.                       |
| Marketplace theme header action          | `AdminBridgeRegistrar::resourceHeaderActionExtender()`                                                                                        | Marketplace admin bridge               | Theme resource header             | Action hidden                 | Render theme resource action list.                                            |
| Marketplace activation verification      | Container binding `capell.marketplace.activation-verifier`                                                                                    | Marketplace provider                   | Install/activation flow           | Signed activation unavailable | Resolve binding and verify signed fixture payload.                            |

## Declared Contributions

Alongside the runtime extension points above, a package declares what it contributes in
its `capell.json` `contributes[]` array. Each entry names a `type` and a `class`, and the
class must implement the contract that `type` maps to.

These contracts are **marker interfaces**. They declare no registration methods — the
only method comes from the base contract, `Capell\Core\Contracts\Extensions\ExtensionContribution`:

```php
public static function compatibleCapellApiVersion(): string;
```

The marker's job is validation and traceability, not wiring. You still register runtime
behaviour through the extension points documented above; the declaration is what lets
manifest audits know the surface exists.

```php
namespace Vendor\Example\Routes;

use Capell\Core\Contracts\Extensions\RegistersExtensionRoute;

final class ExampleRoutes implements RegistersExtensionRoute
{
    public static function compatibleCapellApiVersion(): string
    {
        return '^1.0';
    }
}
```

```jsonc
// capell.json
"contributes": [
  { "type": "route", "class": "Vendor\\Example\\Routes\\ExampleRoutes" },
  { "type": "content-widget", "key": "vendor.hero", "class": "Vendor\\Example\\Widgets\\HeroWidget" }
]
```

| `type` | Contract (in `Capell\Core\Contracts\Extensions`) |
| --- | --- |
| `admin-resource` | `RegistersExtensionAdminResource` |
| `section` | `RegistersExtensionSection` |
| `page-type`, `page-variation` | `RegistersExtensionPageType` |
| `dashboard-widget`, `overview-stat` | `RegistersExtensionFilamentWidget` |
| `permission` | `RegistersExtensionPermission` |
| `route` | `RegistersExtensionRoute` |
| `setting` | `RegistersExtensionSetting` |
| `frontend-component` | `RegistersExtensionFrontendComponent` |
| `content-widget` | `RegistersExtensionContentWidget` |
| `render-hook` | `RegistersExtensionRenderHook` |
| `asset` | `RegistersExtensionAsset` |
| `migration` | `RunsExtensionMigration` |
| `scheduled-job` | `RunsScheduledExtensionJob` |
| `health-check` | `ChecksExtensionHealth` |
| `content-graph` | `ContentGraphExtractor` |
| `workflow-attention` | `ContributesWorkflowAttention` |

Validation rejects a manifest when the class sits outside the package's own PSR-4
namespace, spoofs a Capell namespace, or does not implement the mapped contract. A
`content-widget` also needs a `key` matching `^[a-z0-9][a-z0-9-]*\.[a-z0-9][a-z0-9.-]*$`,
prefixed with your vendor and unique within the manifest. Content widgets were added in
API 1.1, so declare `^1.1` for those.

Verify with `php artisan capell:extension-audit`, explore with
`php artisan capell:extension-playground`, and assert in tests with
`Capell\Core\Testing\ExtensionTestHarness::assertContributionRegistered()`.

## Frontend Route Middleware

An application adding its own frontend route must apply Capell's middleware stack, or
there is no resolved site, page, or language context. Take the canonical list from
`Capell\Frontend\Support\Routing\FrontendRouteMiddlewareRegistry::all()` rather than
hand-listing aliases — it is the source of truth and supports `prepend()`, `append()`,
`insertBefore()`, and `insertAfter()`.

| Alias | Purpose |
| --- | --- |
| `frontend.resolve` | Bootstraps the frontend kernel and resolves site, page, and language. **Required.** |
| `frontend.maintenance` | Serves cached maintenance and lockdown pages. Lockdown ignores all bypasses. |
| `frontend.anonymous_cacheable_render` | Nulls the user resolver so an authenticated response cannot poison a shared HTML cache entry |
| `frontend.rendering_strategy` | Adds an `X-Rendering-Strategy` diagnostic header. Optional, not in the default stack. |
| `frontend.etag`, `frontend.asset-optimization` | ETag and asset optimisation. Optional, not in the default stack. |

## Registering Public Components

`capell-frontend.blade_components` and `capell-frontend.livewire_components` are flat
`name => class` maps and the supported way for a theme or app to register or override a
public component. Entries whose value is not a string — or, for Livewire, not a
`Livewire\Component` subclass — are silently ignored.

Packages should contribute through `Capell\Frontend\Contracts\FrontendComponentContributor`
instead, tagged with that interface's `TAG` constant. Contributor entries take precedence
over config.

## Contracts That Exist Twice

Three contracts exist in both Core and a downstream package. In each case Core owns the
definition and the downstream copy is an empty extending alias — bind the Core one unless
you specifically want the narrower scope.

| Contract | Bind this | Note |
| --- | --- | --- |
| `RedirectResolver` | `Capell\Core\Contracts\RedirectResolver` | Public page resolution reads the **Core** contract. Binding only the Frontend alias will not change redirect behaviour. |
| `SettingsSchemaContract` | `Capell\Core\Contracts\SettingsSchemaContract` | Use the Admin alias only for admin-panel-only settings. |
| `ThemePreviewRendererInterface` | `Capell\Core\Contracts\Themes\ThemePreviewRendererInterface` | Only the Core contract is bound; the Admin alias has no binding and no implementer. |

## Rules

- Register in the package that owns the behavior.
- Return `null`, an empty array, or no-op output when the package does not support the current context.
- Test both the expected contribution and the safe fallback.
- Public frontend extensions must pass [public HTML safety](../frontend/public-html-safety.md).

## Next

- [Build an extension end to end](build-extension-end-to-end.md)
- [Admin extensions](admin-extensions.md)
- [Frontend extensions](frontend-extensions.md)
- [Extension troubleshooting](extension-troubleshooting.md)
