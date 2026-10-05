# Stage 2 provider migration worklist

Read-only source audit: companion Packages `d0c62b87b778c70bb89f6f063e2e306f3f0a6c56`. No companion files changed. The diagnostic sweep used `18d340fc18792524010e797fc3d8370681ac2698`; the four held provider changes listed below are absent from this main checkout. Source locations below refer to the audited checkout.

Use [Installed runtime lifecycle](installed-runtime-lifecycle.md) as the migration contract. Every row needs the manifest-derived parity test, an uninstalled-absence assertion, declared-surface expectations, preloaded child coverage where present, and a minimum Core version containing the new API. Do not remove installation commands, configuration, or a deliberately pre-install listener. No package dependency constraints were changed in stage 1.

## Revised adopter contract

A failed installed-runtime hook or panel refresh is terminal for that application. Do not add retry flags or make callers re-run partially completed registrations; replace the application and restore eligibility after fixing the cause. Test a listener registered before a deliberate failure and require same-application retry to throw without adding a second listener.

Enrol from `register()` and put hook prerequisites in lifecycle-safe registration, because Admin activates enrolled hooks before constructing panel routes. Required providers must enrol before dependants; use the manifest buckets for every child. Enable loads new hook adopters only and preserves unloaded legacy-provider behaviour.

For Admin packages, test fresh installed routing plus uninstalled boot followed by install: existing route middleware must protect grouped, unnamed, tenant and Livewire replay paths; new pages/resources remain absent from the current panel until a full reload after cache reconciliation. Core persists extender middleware automatically. Topology-changing extenders must defer to fresh bootstrap. A failed security refresh makes subsequent HTTP requests return 503. Password Policy still needs execution-time enabled checks after disable/uninstall.

The direct Admin install action enforces the cache-clear/full-redirect boundary. CLI, queued installation and deployments must refresh persisted route/component caches and reload retained processes. Octane sandbox admission must fail before migrations, install Actions or member state changes. Test bundle restart emission once after success, and its absence at boot, per request and after failure.

## Application callbacks — 22 packages

Move the installed branches from application booting/booted callbacks and the listed Spatie hooks into the main installed-runtime hook. Leave lifecycle-safe bindings in registration. Child providers need the adapter; remove their callbacks after moving the bodies.

| Package | Exact provider phases to migrate | Missing surfaces to assert | Additional handling |
| --- | --- | --- | --- |
| access-gate | `packages/access-gate/src/Providers/AccessGateServiceProvider.php` — `packageRegistered:133`, `packageBooted:174` | admin-surfaces, listeners, models, policies, protected-table-registrations, protected-tables, tags | Keep lifecycle-safe bindings, then migrate installed models/policies/resources, rules/hooks, protected tables, integrations and access-request notifications. |
| ai-orchestrator | `packages/ai-orchestrator/src/Providers/AIOrchestratorServiceProvider.php` — `registeringPackage:78` | listeners, schedule, tags | Retain only lifecycle-safe work in the old phases; remove migrated call sites. |
| comments | `packages/comments/src/Providers/CommentsServiceProvider.php` — `registeringPackage:66`, `packageRegistered:84`, `packageBooted:105`<br>`packages/comments/src/Providers/AdminServiceProvider.php` — `boot:21` | admin-surfaces, listeners, models, protected-table-registrations, protected-tables, renderables, routes, tags | Retain only lifecycle-safe work in the old phases; remove migrated call sites. |
| contacts | `packages/contacts/src/Providers/ContactsServiceProvider.php` — `packageRegistered:62`, `packageBooted:92`<br>`packages/contacts/src/Providers/AdminServiceProvider.php` — `register:28` | admin-surfaces, listeners, models, policies, protected-table-registrations, protected-tables | Retain only lifecycle-safe work in the old phases; remove migrated call sites. |
| customer-portal | `packages/customer-portal/src/Providers/CustomerPortalServiceProvider.php` — `packageRegistered:49`, `packageBooted:73`<br>`packages/customer-portal/src/Providers/AdminServiceProvider.php` — `register:20` | admin-surfaces, listeners, models, policies, protected-table-registrations, protected-tables | Retain only lifecycle-safe work in the old phases; remove migrated call sites. |
| document-lifecycle | `packages/document-lifecycle/src/Providers/DocumentLifecycleServiceProvider.php` — `packageRegistered:51`, `packageBooted:75` | admin-surfaces, listeners, models, policies, protected-table-registrations, protected-tables, schedule | Retain only lifecycle-safe work in the old phases; remove migrated call sites. |
| email-studio | `packages/email-studio/src/Providers/EmailStudioServiceProvider.php` — `registeringPackage:99`, `packageRegistered:108`, `packageBooted:140`<br>`packages/email-studio/src/Providers/AdminServiceProvider.php` — `boot:32` | admin-surfaces, listeners, models, policies, protected-table-registrations, protected-tables, schedule | Retain only lifecycle-safe work in the old phases; remove migrated call sites. |
| experiments | `packages/experiments/src/Providers/ExperimentsServiceProvider.php` — `packageRegistered:61` | admin-surfaces, models, policies, protected-table-registrations, protected-tables, schedule | Retain only lifecycle-safe work in the old phases; remove migrated call sites. |
| filament-peek | `packages/filament-peek/src/Providers/FilamentPeekServiceProvider.php` — `registeringPackage:31`, `packageRegistered:49` | tags | Retain only lifecycle-safe work in the old phases; remove migrated call sites. |
| frontend-optimizer | `packages/frontend-optimizer/src/Providers/FrontendOptimizerServiceProvider.php` — `registeringPackage:77`, `packageRegistered:93` | listeners, tags | Retain only lifecycle-safe work in the old phases; remove migrated call sites. |
| ga4-reports | `packages/ga4-reports/src/Providers/GA4ReportsServiceProvider.php` — `registeringPackage:46`, `packageRegistered:54`, `packageBooted:63`, `registerInstalledPackageSurfacesWhenReady:99`, `registerInstalledPackageSurfaces:118`<br>`packages/ga4-reports/src/Providers/AdminServiceProvider.php` — `boot:33` | admin-surfaces, commands, extension-pages, models, protected-table-registrations, protected-tables, schedule, tags | Replace registerInstalledPackageSurfacesWhenReady and packageSurfacesRegistered only after moving all main and child surfaces. |
| insights | `packages/insights/src/Providers/InsightsServiceProvider.php` — `registeringPackage:52`, `packageRegistered:60`, `packageBooted:79`, `bootInstalledPackage:114`<br>`packages/insights/src/Providers/AdminServiceProvider.php` — `register:37`, `boot:43` | admin-surfaces, commands, extension-pages, listeners, models, protected-table-registrations, protected-tables, schedule, tags | Retain only lifecycle-safe work in the old phases; remove migrated call sites. |
| live-chat | `packages/live-chat/src/Providers/LiveChatServiceProvider.php` — `registeringPackage:89`, `packageRegistered:104`, `packageBooted:129` | admin-surfaces, listeners, models, policies, protected-table-registrations, protected-tables, routes, schedule | Retain only lifecycle-safe work in the old phases; remove migrated call sites. |
| login-audit | `packages/login-audit/src/Providers/LoginAuditServiceProvider.php` — `registeringPackage:54`, `packageRegistered:63`, `packageBooted:82`<br>`packages/login-audit/src/Providers/AdminServiceProvider.php` — `register:31`, `boot:49` | admin-surfaces, listeners, models, policies, protected-table-registrations, protected-tables, schedule, tags | Retain only lifecycle-safe work in the old phases; remove migrated call sites. |
| migration-assistant | `packages/migration-assistant/src/Providers/MigrationAssistantServiceProvider.php` — `packageRegistered:84`, `registerInstalledPackage:103` | listeners, models, policies | Retain only lifecycle-safe work in the old phases; remove migrated call sites. |
| payments | `packages/payments/src/Providers/PaymentsServiceProvider.php` — `packageRegistered:71`<br>`packages/payments/src/Providers/AdminServiceProvider.php` — `register:32` | models, protected-table-registrations, protected-tables, tags | Retain only lifecycle-safe work in the old phases; remove migrated call sites. |
| privacy-center | `packages/privacy-center/src/Providers/PrivacyCenterServiceProvider.php` — `packageRegistered:56`, `packageBooted:76`<br>`packages/privacy-center/src/Providers/AdminServiceProvider.php` — `register:28` | listeners, models, protected-table-registrations, protected-tables, routes, schedule | Retain only lifecycle-safe work in the old phases; remove migrated call sites. |
| public-actions | `packages/public-actions/src/Providers/PublicActionsServiceProvider.php` — `packageRegistered:79` | admin-surfaces, listeners, models, policies, protected-table-registrations, protected-tables | Retain only lifecycle-safe work in the old phases; remove migrated call sites. |
| shopify-commerce | `packages/shopify-commerce/src/Providers/ShopifyCommerceServiceProvider.php` — `registeringPackage:58`, `packageRegistered:68`<br>`packages/shopify-commerce/src/Providers/AdminServiceProvider.php` — `register:20`, `boot:26` | admin-surfaces, commands, extension-pages, listeners, models, protected-table-registrations, protected-tables, schedule | Retain only lifecycle-safe work in the old phases; remove migrated call sites. |
| social-feeds | `packages/social-feeds/src/Providers/SocialFeedsServiceProvider.php` — `packageRegistered:52`<br>`packages/social-feeds/src/Providers/AdminServiceProvider.php` — `register:22`, `boot:33` | admin-surfaces, models, policies, protected-table-registrations, protected-tables | The retained provider registry built through callAfterResolving must see late tags; include that consumer in the package snapshot. |
| structured-content-library | `packages/structured-content-library/src/Providers/StructuredContentLibraryServiceProvider.php` — `packageRegistered:43` | admin-surfaces, listeners, models, policies, protected-table-registrations, protected-tables | Retain only lifecycle-safe work in the old phases; remove migrated call sites. |
| tags | `packages/tags/src/Providers/TagsServiceProvider.php` — `registeringPackage:38`<br>`packages/tags/src/Providers/AdminServiceProvider.php` — `register:18`, `boot:28` | listeners, models, policies | Retain only lifecycle-safe work in the old phases; remove migrated call sites. |

## Spatie installed branches — 12 packages

Move the installed branch of packageBooted into bootInstalledRuntime. Consolidate any existing bootInstalledPackage body. Keep pre-install work outside the hook. Apply the adapter to the listed children.

| Package | Exact provider phases to migrate | Missing surfaces to assert | Additional handling |
| --- | --- | --- | --- |
| agent-delivery | `packages/agent-delivery/src/Providers/AgentDeliveryServiceProvider.php` — `registeringPackage:41`, `packageBooted:48` | tags | Retain only lifecycle-safe work in the old phases; remove migrated call sites. |
| ai-creator | `packages/ai-creator/src/Providers/AiCreatorServiceProvider.php` — `registeringPackage:43`, `packageBooted:52` | admin-surfaces, extension-pages, models | Keep the pre-install CapellInstalled listener in packageBooted; move only installed models and the extension page. |
| automation-studio | `packages/automation-studio/src/Providers/AutomationStudioServiceProvider.php` — `packageRegistered:50`, `packageBooted:59`<br>`packages/automation-studio/src/Providers/AdminServiceProvider.php` — `register:17` | admin-surfaces, listeners, models, policies, protected-table-registrations, protected-tables | Retain only lifecycle-safe work in the old phases; remove migrated call sites. |
| form-builder | `packages/form-builder/src/Providers/FormBuilderServiceProvider.php` — `registeringPackage:75`, `packageBooted:96`, `bootInstalledPackage:140` | schedule | Merge the existing guarded installed body with the schedule and morph-map branch still in packageBooted. |
| html-cache | `packages/html-cache/src/Providers/HtmlCacheServiceProvider.php` — `registeringPackage:122`, `packageBooted:189` | admin-surfaces, extension-pages, listeners, livewire, tags | Retain required early container configuration; move installed maintenance/error storage, admin integrations, invalidation listeners and schedules. Permission writes belong in install Actions. |
| inertia-react-adapter | `packages/inertia-react-adapter/src/Providers/InertiaReactAdapterServiceProvider.php` — `packageBooted:39` | tags | Retain only lifecycle-safe work in the old phases; remove migrated call sites. |
| inertia-vue-adapter | `packages/inertia-vue-adapter/src/Providers/InertiaVueAdapterServiceProvider.php` — `packageBooted:39` | tags | Retain only lifecycle-safe work in the old phases; remove migrated call sites. |
| newsletter | `packages/newsletter/src/Providers/NewsletterServiceProvider.php` — `registeringPackage:83`, `packageRegistered:91`, `packageBooted:105`, `bootInstalledPackage:126`<br>`packages/newsletter/src/Providers/AdminServiceProvider.php` — `register:59`, `boot:81`, `formBuilderIsInstalled:312` | admin-surfaces, commands, policies, routes, schedule | Merge the guarded main body with routes/morph map; the child loses policies, resources, commands, dashboard contributions and schedules. |
| record-switcher | `packages/record-switcher/src/Providers/RecordSwitcherServiceProvider.php` — `packageBooted:30` | tags | Retain only lifecycle-safe work in the old phases; remove migrated call sites. |
| search | `packages/search/src/Providers/SearchServiceProvider.php` — `registeringPackage:79`, `packageRegistered:87`, `packageBooted:101`<br>`packages/search/src/Providers/AdminServiceProvider.php` — `register:35`, `boot:41` | commands, listeners, models, protected-table-registrations, protected-tables, schedule, tags | Retain only lifecycle-safe work in the old phases; remove migrated call sites. |
| site-monitor | `packages/site-monitor/src/Providers/SiteMonitorServiceProvider.php` — `registeringPackage:48`, `packageBooted:59`<br>`packages/site-monitor/src/Providers/AdminServiceProvider.php` — `register:23`, `boot:34` | admin-surfaces, extension-pages, models, policies, protected-table-registrations, protected-tables, schedule | The child has installed branches in both register and boot; consolidate both. |
| smart-404 | `packages/smart-404/src/Providers/Smart404ServiceProvider.php` — `registeringPackage:47`, `packageBooted:54`<br>`packages/smart-404/src/Providers/AdminServiceProvider.php` — `boot:16` | listeners | Retain only lifecycle-safe work in the old phases; remove migrated call sites. |

## Ordinary Laravel main providers — 3 packages

Add RegistersInstalledRuntime and enrol from register with the package name and runtime bucket. Move installed register/boot/application-callback bodies into bootInstalledRuntime; apply the admin adapter separately.

| Package | Exact provider phases to migrate | Missing surfaces to assert | Additional handling |
| --- | --- | --- | --- |
| agent-bridge | `packages/agent-bridge/src/Providers/AgentBridgeServiceProvider.php` — `register:50`, `boot:56`, `registerInstalledPackage:71` | admin-surfaces, commands, extension-pages, models, routes, tags | Retain only lifecycle-safe work in the old phases; remove migrated call sites. |
| media-library | `packages/media-library/src/MediaLibraryServiceProvider.php` — `register:31`, `boot:46`, `registerInstalledPackage:73` | commands, extension-pages, listeners, models | Retain only lifecycle-safe work in the old phases; remove migrated call sites. |
| publishing-studio | `packages/publishing-studio/src/Providers/PublishingStudioServiceProvider.php` — `register:89`, `boot:108`<br>`packages/publishing-studio/src/Providers/AdminServiceProvider.php` — `register:78`, `boot:91` | listeners, livewire, models, policies, routes, schedule, tags, views | Move the nested booted draftable registration too; retain the early schema-cache invalidation listener only if it is required before installation. |

## Preloaded Admin children — 2 packages

Enrol the child with RegistersInstalledRuntime and the admin bucket; move installed registration out of register/boot. Keep the main provider responsible for metadata and lifecycle-safe bindings.

| Package | Exact provider phases to migrate | Missing surfaces to assert | Additional handling |
| --- | --- | --- | --- |
| dashboard-reports | `packages/dashboard-reports/src/Providers/DashboardReportsServiceProvider.php` — `registeringPackage:32`, `packageRegistered:39`<br>`packages/dashboard-reports/src/Providers/AdminServiceProvider.php` — `boot:21` | tags | Retain only lifecycle-safe work in the old phases; remove migrated call sites. |
| diagnostics | `packages/diagnostics/src/Providers/DiagnosticsServiceProvider.php` — `registeringPackage:41`, `packageBooted:49`<br>`packages/diagnostics/src/Providers/AdminServiceProvider.php` — `register:35`, `boot:52` | admin-surfaces, extension-pages | The source harness preloads the child. This is a contract case; normal production preloading is not established. |

## Overridden boot — 1 package

Keep parent boot semantics; move the installed body after parent::boot into bootInstalledRuntime. Remove that old call site so it cannot execute twice.

| Package | Exact provider phases to migrate | Missing surfaces to assert | Additional handling |
| --- | --- | --- | --- |
| frontend-authoring | `packages/frontend-authoring/src/Providers/FrontendAuthoringServiceProvider.php` — `boot:40`, `registeringPackage:56` | livewire, routes, views | Move translations, view namespaces, middleware aliases, gates, Livewire and routes from the post-parent boot branch. |

## Existing manual guards — 25 provider files

The supplied checkout contains 25 provider files with installed-runtime or installed-surface flags across 21 packages, including the GA4 surface guard. This is not 25 distinct packages, and Form Builder, Newsletter and GA4 overlap the 40 failures above. The table names every flag-bearing file; it does not infer completeness from an existing guard.

| Provider | Installed phases to consolidate | Flags removable after full migration | Handling |
| --- | --- | --- | --- |
| `packages/address/src/Providers/AddressServiceProvider.php` | `registeringPackage:76`, `bootInstalledPackage:96` | `$installedRuntimeBooted` | Move the early resource callback into the installed phase as well. |
| `packages/blog/src/Providers/AdminServiceProvider.php` | `register:37`, `boot:46` | `$installedRuntimeBooted` | Main: include feed routes; child: replace the explicit provider booted callback with the admin adapter. |
| `packages/blog/src/Providers/BlogServiceProvider.php` | `registeringPackage:91`, `bootInstalledPackage:111` | `$installedRuntimeBooted` | Main: include feed routes; child: replace the explicit provider booted callback with the admin adapter. |
| `packages/bookings/src/Providers/BookingsServiceProvider.php` | `registeringPackage:176`, `packageBooted:217`, `bootInstalledPackage:241` | `$installedPackageBooted`, `$installedRuntimeBooted` | Consolidate packageBooted/routes and bootInstalledPackage; remove the explicit packageBooted replay. |
| `packages/campaign-studio/src/Providers/CampaignStudioServiceProvider.php` | `registeringPackage:72`, `bootInstalledPackage:86` | `$installedRuntimeBooted` | Main hook consolidation; the held child-provider fix must be migrated too. |
| `packages/content-sections/src/Providers/ContentSectionsServiceProvider.php` | `packageRegistered:70`, `registeringPackage:93`, `bootInstalledPackage:123` | `$installedRuntimeBooted` | Remove the early resource callback; retain sectionRegistryBootstrapped only for a genuinely separate registry initialisation boundary. |
| `packages/demo-kit/src/Providers/DemoKitServiceProvider.php` | `registeringPackage:70`, `packageBooted:90` | `$installedRuntimeBooted` | Move guarded packageBooted wiring and delete its replay callback; keep demo/install commands available early. |
| `packages/discovery-foundation/src/Providers/DiscoveryFoundationServiceProvider.php` | `bootInstalledPackage:29` | `$installedRuntimeBooted` | Move the guarded installed bindings and tag to the new hook. |
| `packages/events/src/Providers/EventsServiceProvider.php` | `registeringPackage:95`, `bootInstalledPackage:141` | `$installedRuntimeBooted` | Consolidate the early admin-resource callback with installed wiring. |
| `packages/extension-cookbook/src/Providers/ExtensionCookbookServiceProvider.php` | `bootInstalledPackage:49` | `$installedRuntimeBooted` | Migrate the guarded main body plus the held Frontend child change; preserve frozen registry contribution tests. |
| `packages/form-builder/src/Providers/FormBuilderServiceProvider.php` | `registeringPackage:75`, `packageBooted:96`, `bootInstalledPackage:140` | `$installedRuntimeBooted` | Merge the existing guarded installed body with the schedule and morph-map branch still in packageBooted. |
| `packages/ga4-reports/src/Providers/GA4ReportsServiceProvider.php` | `registeringPackage:46`, `packageRegistered:54`, `packageBooted:63`, `registerInstalledPackageSurfaces:118` | `$packageSurfacesRegistered` | Replace registerInstalledPackageSurfacesWhenReady and packageSurfacesRegistered only after moving all main and child surfaces. |
| `packages/knowledge-base/src/Providers/AdminServiceProvider.php` | `register:24` | `$installedRuntimeBooted` | Move the application callback and guarded route helper into one installed phase; child uses the admin adapter. |
| `packages/knowledge-base/src/Providers/KnowledgeBaseServiceProvider.php` | `packageRegistered:59`, `bootPackageRoutes:85` | `$installedRuntimeBooted`, `$installedRoutesBooted` | Move the application callback and guarded route helper into one installed phase; child uses the admin adapter. |
| `packages/navigation/src/Providers/NavigationServiceProvider.php` | `register:69`, `boot:90`, `registerInstalledPackage:108` | `$installedPackageRegistered` | This is an ordinary Laravel provider: use the adapter and consolidate registerInstalledPackage with its boot callback. |
| `packages/newsletter/src/Providers/NewsletterServiceProvider.php` | `registeringPackage:83`, `packageRegistered:91`, `packageBooted:105`, `bootInstalledPackage:126` | `$installedRuntimeBooted` | Merge the guarded main body with routes/morph map; the child loses policies, resources, commands, dashboard contributions and schedules. |
| `packages/notes/src/Providers/AdminServiceProvider.php` | `register:22`, `boot:34` | `$installedRuntimeBooted` | Consolidate main installed work and child boot/replay/beforeResolving registrations; include the early policy in absence assertions. |
| `packages/notes/src/Providers/NotesServiceProvider.php` | `registeringPackage:52`, `bootInstalledPackage:66` | `$installedRuntimeBooted` | Consolidate main installed work and child boot/replay/beforeResolving registrations; include the early policy in absence assertions. |
| `packages/referer/src/Providers/AdminServiceProvider.php` | `register:19`, `boot:27` | `$installedRuntimeBooted` | Migrate main and child independently; remove the child callback that invokes boot again. |
| `packages/referer/src/Providers/RefererServiceProvider.php` | `registeringPackage:41`, `bootInstalledPackage:58` | `$installedPackageBooted` | Migrate main and child independently; remove the child callback that invokes boot again. |
| `packages/seo-suite/src/Providers/SeoSuiteServiceProvider.php` | `registeringPackage:239`, `packageBooted:247`, `bootInstalledPackage:259` | `$installedRuntimeBooted` | Move the installed body; audit early Livewire and content-graph tags against absence assertions. |
| `packages/site-discovery/src/Providers/SiteDiscoveryServiceProvider.php` | `registeringPackage:83`, `bootInstalledPackage:110` | `$installedRuntimeBooted` | Retain lifecycle-safe URL-change plumbing only when needed before install; move all installed runtime wiring together. |
| `packages/theme-foundation/src/Providers/FoundationThemeServiceProvider.php` | `packageBooted:210`, `packageRegistered:260` | `$installedRuntimeBooted` | Preserve sharedRenderingBooted and its shared-rendering condition. Migrate only installed-theme work; test theme availability separately. |
| `packages/url-manager/src/Providers/UrlManagerServiceProvider.php` | `bootInstalledPackage:57` | `$installedRuntimeBooted` | Move the guarded installed body including redirect capture and schedule. |
| `packages/welcome-tour/src/Providers/WelcomeTourServiceProvider.php` | `registeringPackage:62`, `bootInstalledPackage:77` | `$installedRuntimeBooted` | Migrate installed contributions and configured-tour registration; remove panelExtenderTagged only after panel timing is covered. |

## Held source differences to reconcile before dispatch

| File absent from the audited main fix set | Stage 2 action |
| --- | --- |
| `packages/campaign-studio/src/Providers/AdminServiceProvider.php` | Replace current booting/boot registration with the admin adapter; consolidate the held guard rather than stacking another callback. |
| `packages/extension-cookbook/src/Providers/FrontendServiceProvider.php` | Use the frontend adapter; move routes, namespaces, assets, payload contributions and tags into the hook. |
| `packages/layout-builder/src/LayoutBuilderServiceProvider.php` | Consolidate early resource callbacks, packageBooted listeners/schedules/routes and bootInstalledPackage page types. The supplied main checkout has no installed-runtime flag to delete. |
| `packages/password-policy/src/Providers/PasswordPolicyServiceProvider.php` | Consolidate settings and installed admin surfaces; migrate the early panel extender after testing an existing panel and real forced-change request. Main uses container inspection for deduplication rather than an installedRuntimeBooted flag. |

Theme Directory inherits Foundation behaviour through its provider; verify it in the manifest-derived main-provider sweep even though it owns no manual installed-runtime flag. Keep pre-install listeners in AI Creator, the shared-rendering phase in Theme Foundation, and Password Policy security behaviour as explicit tests.

## Release boundary

Do not ship the package migration until stage 1 is released or pinned and each consumer proves the fresh-application route/component-cache boundary. New page/resource routing, persisted caches, live Octane reload and cross-process propagation require consumer proof beyond provider snapshots.
