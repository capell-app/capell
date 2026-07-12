# Extension Troubleshooting

Use this when a package extension does not appear, does not run, or behaves differently between admin and public requests. Start with the smallest check that proves whether Capell can see the package, then move toward the specific runtime.

For focused debugging paths, use:

| Problem                                         | Runbook                                                              |
| ----------------------------------------------- | -------------------------------------------------------------------- |
| Composer, manifest, or provider discovery       | [Debugging package discovery](debugging-package-discovery.md)        |
| Admin pages, fields, actions, widgets, settings | [Debugging admin extensions](../admin/debugging-admin-extensions.md) |
| Public output, cache bypasses, unsafe HTML      | [Debugging public output](../frontend/debugging-public-output.md)    |
| Marketplace connection, verification, heartbeat | [Debugging Marketplace](../operations/debugging-marketplace.md)      |

## First Checks

Run these before changing code:

```bash
composer dump-autoload
php artisan optimize:clear
php artisan list capell
php artisan capell:package-cache:clear
```

If the package contributes admin configurators or widgets, also run:

```bash
php artisan capell:admin-clear-cache
php artisan capell:admin-cache-configurators
php artisan capell:admin-cache-widgets
```

Confirm the package service provider is loaded through Composer discovery, the host app's provider list, or the package's `capell.json` provider entries. A missing provider is the most common reason every downstream extension point appears broken.

## Package Not Discovered

| Symptom                                                     | Likely cause                                                         | Check                                                                 | Fix                                                                                                                 |
| ----------------------------------------------------------- | -------------------------------------------------------------------- | --------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------- |
| `php artisan list capell` does not show package commands    | Composer autoload or provider discovery is stale                     | `composer show capell-app/<package>` and `composer dump-autoload -o`  | Reinstall/update the package, fix `composer.json` autoload, then clear Laravel and Capell package caches.           |
| Package appears in Composer but not in Capell package state | `capell.json` is missing, invalid, or not loaded                     | Inspect `capell.json`; run package manifest tests when available      | Fix manifest name, providers, surfaces, and version constraints, then run `php artisan capell:package-cache:clear`. |
| Install/setup runs but package data is missing              | Install command skipped settings migrations or package setup actions | Check package install command output and `php artisan migrate:status` | Register settings migrations and run setup through Actions rather than writing from providers.                      |

## Composer Drift

Composer drift means `capell_extensions` and the current Composer/package registry no longer agree. The Extensions dashboard reports drift as a health alert, but it is read-only: loading `/admin/extensions` must never run `composer require`.

Capell classifies drift into four reasons:

| Reason                       | What it means                                                                   | Repair path                                                                                              |
| ---------------------------- | ------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------- |
| Missing registry manifest    | A `capell_extensions` row exists, but the current registry has no manifest      | Review the record manually. The package may have been removed, renamed, or stopped registering metadata. |
| Composer unavailable         | The registry manifest exists, but Composer does not expose the package          | Run `php artisan capell:extensions:repair-composer-drift vendor/example`.                                |
| Version mismatch             | Composer exposes a different version than the Capell extension record           | Run the repair command, then verify the extension install/upgrade path if metadata still disagrees.      |
| Disabled or failed in Capell | Composer exposes the package, but `capell_extensions.status` blocks runtime use | Review status, runtime gate, and recent install/upgrade failures before re-enabling the extension.       |

For one package, run:

```bash
php artisan capell:extensions:repair-composer-drift vendor/example
```

For scheduled or operator-approved bulk repair, enable the gate first:

```bash
CAPELL_EXTENSIONS_COMPOSER_DRIFT_AUTO_FIX=true
php artisan capell:extensions:repair-composer-drift --all
```

`--all --force` bypasses the config gate for a single manual run. Use it only when you have already reviewed the dashboard alerts and know the drift is Composer-actionable.

The latest repair attempt is recorded on `capell_extensions.metadata` with `composer_drift_last_repair_attempted_at`, `composer_drift_last_repair_status`, `composer_drift_last_repair_message`, and `composer_drift_last_detected_reason`. The dashboard alert includes the latest repair status/message when present.

## Admin Surface Missing

| Symptom                                              | Likely cause                                                                                         | Check                                                                                         | Fix                                                                                                                                                                            |
| ---------------------------------------------------- | ---------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| Resource/page/widget does not show in navigation     | Contribution was not registered, permission denies access, or navigation is intentionally suppressed | Check the package admin provider, `AdminBridge::register()`, and the user's permissions       | Prefer `AdminBridgeRegistrar::resource()`, `page()`, `widget()`, or `filamentDashboardWidget()`. Re-run `capell:admin-install` when permissions changed.                       |
| An Extensions page edit action is missing            | The page was registered as a normal page instead of an extension page                                | Search for `registerExtensionPage(...)`                                                       | Register the package control page with `CapellAdmin::registerExtensionPage($packageName, PageClass::class)`.                                                                   |
| Dashboard Filament widget is registered but disabled | Widgets are available but admin settings hide them                                                   | Check Admin settings and widget defaults                                                      | Use `registerOverviewStat()` for small metrics or enable the widget through Admin settings or `setEnabledWidgets()`.                                                           |
| Form fields are missing                              | Wrong extender tag, wrong hook enum, or configurator cache is stale                                  | Check tags such as `PageSchemaExtender::TAG` and `SiteSchemaExtender::TAG`                    | Tag the extender in the provider or `AdminBridgeRegistrar::schemaExtender()`, then clear/cache admin configurators.                                                            |
| Header/table actions are missing                     | Extender targets the wrong surface                                                                   | Check whether the target is page, site, resource, or Extensions page                          | Use the matching extender tag: `PageHeaderActionExtender`, `SiteHeaderActionExtender`, `ResourceHeaderActionExtender`, `PageTableExtender`, or `ExtensionsPageActionRegistry`. |
| User fields or relation managers are missing         | User schema bridge/extender does not support the current context                                     | Check `UserSchemaExtender::supports()` or the package `UserResourceBridge::isEnabled()` logic | Use `AbstractUserSchemaExtender` for no-op defaults, and test the resolver with the same user model/context used by the resource.                                              |
| Publish panel content is missing                     | The package used a page schema extender instead of `PublishPanelExtender`                            | Search for `PublishPanelExtender::TAG`                                                        | Tag a `PublishPanelExtender` and return a `View`, HTML string, or `null` from `extendPanel()`.                                                                                 |
| Admin route works on one host but not another        | `CAPELL_ADMIN_PATH` or `CAPELL_ADMIN_DOMAIN` is cached                                               | `php artisan config:show capell-admin.path` and `php artisan config:show capell-admin.domain` | Update env/config and run `php artisan optimize:clear`. Signed preview URLs must be regenerated after host/path changes.                                                       |

## Settings Page Missing

| Symptom                                  | Likely cause                                                 | Check                                                                         | Fix                                                                                                                 |
| ---------------------------------------- | ------------------------------------------------------------ | ----------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------- |
| Settings group is absent                 | Settings class or schema was not registered                  | Search for `SettingsSchemaRegistry::registerSettingsClass()` and `register()` | Register both the settings class and schema, or use `AdminBridgeRegistrar::settingsClass()` and `settingsSchema()`. |
| Settings page label/icon is generic      | Metadata is missing                                          | Search for `registerMetadata(new SettingsGroupMetadata(...))`                 | Register metadata with translated labels and the correct group key.                                                 |
| Settings save fails on fresh installs    | Settings migration did not run or is not idempotent          | Check `database/settings/` and install/setup command registration             | Add guarded settings migrations and cover fresh install plus upgrade paths.                                         |
| Settings appear in tests but not browser | Config/package cache is stale or provider boot order differs | Clear caches and inspect provider registration timing                         | Register settings in `boot()` or after resolving `SettingsSchemaRegistry` when package load order matters.          |

## Frontend Output Missing Or Unsafe

| Symptom                                        | Likely cause                                                                                              | Check                                                                                                       | Fix                                                                                                                                                          |
| ---------------------------------------------- | --------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| Render hook output is absent                   | Wrong location, scenario, target, or provider timing                                                      | Search for `RenderHookRegistry::register(...)`; compare location/scenario/target with the Blade call        | Register in `boot()` and match the exact `RenderHookLocation`, scenario, and target used by the component.                                                   |
| Public page route catches package/admin URLs   | Path was not reserved before the frontend fallback route                                                  | Inspect `ReservedFrontendPathRegistry` registrations and route order                                        | Reserve exact paths or prefixes from the package provider, then clear route/config cache.                                                                    |
| Public package route returns 404               | Route file/provider was not loaded or frontend fallback owns the path first                               | `php artisan route:list \| grep <path>`                                                                     | Load the package route provider and reserve the path if it should not fall through to page rendering.                                                        |
| Public page middleware runs in the wrong order | Package middleware was appended/prepended without considering frontend resolution                         | Inspect `FrontendRouteMiddlewareRegistry::all()` in a focused test                                          | Use `insertAfter()` or `insertBefore()` around a known middleware instead of broad prepend/append.                                                           |
| Component alias resolves to a raw Blade name   | Component was registered with Blade/Livewire but not `FrontendComponentRegistry`                          | Check `FrontendComponentRegistryInterface::has()` and `hasReference()`                                      | Register a stable key and aliases through `FrontendComponentRegistryInterface`.                                                                              |
| Frontend rule condition never matches          | Condition key differs from the stored settings/runtime rule                                               | Search for `FrontendRuleCondition::key()` and the stored rule key                                           | Register the condition class and keep the key stable; cover it with a direct condition test.                                                                 |
| HTML cache bypasses a page                     | Public output contains authoring markers, field paths, model IDs, package internals, or signed admin URLs | Check response headers for `X-Frontend-Cache: BYPASS`; inspect rendered HTML as anonymous user              | Remove authoring state from public Blade. Frontend authoring must load after page load from an authenticated admin beacon.                                   |
| Tailwind classes are missing                   | Package sources/imports were not registered or Vite cannot resolve a symlinked dependency                 | Run the frontend asset report command when installed; inspect `TailwindAssetsRegistry::toReport()` in tests | Register sources/imports through `TailwindAssetsRegistry`, install missing npm packages, or add a narrow Vite alias.                                         |
| Page shows old content                         | Static HTML or fragment cache is stale                                                                    | Check queue workers, the installed cache package's output path, and cache invalidation registrations        | Run `php artisan capell:html-cache:clear` when available; if using the HTML cache package, run `php artisan capell:static-site`; keep queue workers running. |

## Cache Invalidation Not Running

| Symptom                                       | Likely cause                                                       | Check                                                   | Fix                                                                                                              |
| --------------------------------------------- | ------------------------------------------------------------------ | ------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------- |
| Model changes do not clear package output     | The model was not registered with `CacheInvalidationRegistry`      | Search for `registerDependency(Model::class, ...)`      | Register exact cache keys where possible. Wildcard patterns intentionally flush the whole `capell-frontend` tag. |
| Invalidation works locally but not production | Queue worker, cache driver, or tag support differs                 | Check `QUEUE_CONNECTION`, cache driver, and worker logs | Use a supported cache driver for tagged cache behavior and run workers for async invalidation.                   |
| Fragment output remains stale                 | Fragment uses a different key/surrogate key than invalidation code | Compare `@cache(...)` keys with invalidation calls      | Align surrogate keys and avoid ad hoc cache key construction in Blade.                                           |

## Marketplace Or Remote Package Issues

| Symptom                                             | Likely cause                                                                       | Check                                                                                        | Fix                                                                                                |
| --------------------------------------------------- | ---------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------- |
| Package does not appear in browser installer        | Composer cannot resolve it or manifest metadata is incomplete                      | `composer show capell-app/<package> --available`; inspect `capell.json` provider/scopes/kind | Fix Composer auth/repositories and manifest metadata, then clear package/config cache.             |
| Package appears but cannot be selected with a theme | Theme key/package metadata is missing or requirements are unmet                    | Check `kind`, `themeKey`, `extends`, and requirements in `capell.json`                       | Keep theme metadata in the manifest and make the package installable before opening `/install`.    |
| Install guide patch is missing                      | Patch was not registered in `PatchRegistry` or `probe()` says it is not applicable | Search `registerPatches()` and inspect the patch `reason()`                                  | Register the patch, keep `probe()` idempotent, and cover the host-file state with a patch test.    |
| Catalogue loads but install is blocked              | Site is not connected or install authorization is denied by Capell App             | Open Marketplace diagnostics and heartbeat state                                             | Connect a Capell account, resolve diagnostics, then request authorization again.                   |
| `api/registration-sessions` route is missing        | Marketplace API URL uses the old unversioned path                                  | `php artisan config:show capell-marketplace.marketplace.base_url`                            | Use `https://capell.app/api/v1`, then run `php artisan config:clear`.                              |
| Domain verification fails                           | Exact host mismatch, expired challenge, or public `.well-known` path blocked       | Fetch the challenge URL from outside the server                                              | Verify the exact production host, remove auth/CDN blocks, and restart verification.                |
| Account linking callback fails                      | Stale approval URL, expired session, invalid state, or missing `APP_URL` host      | Latest `marketplace_account_connection_sessions.last_error`                                  | Set `APP_URL`, clear config, and start a fresh account connection from the same browser session.   |
| Heartbeat fails after account linking               | No public webhook URL, no instance, or Marketplace API unreachable                 | `PhoneHomeAction::lastFailureMessage()` and latest `marketplace_instances` row               | Set `CAPELL_MARKETPLACE_WEBHOOK_URL` when needed, confirm network access, and run heartbeat again. |

## When To Add A Test

Add or update a focused test when the issue crossed one of these boundaries:

- package discovery, manifest validation, or install/setup behavior;
- admin resources, settings, widgets, extenders, permissions, or navigation;
- public rendering, route fallback, render hooks, cache output, or Tailwind assets;
- marketplace authorization, account linking, diagnostics, or domain verification.

For public output, test anonymous and non-admin responses directly. Cached/static HTML must match the same safe output and must not contain authoring markers, model IDs, field paths, selectors, signed admin URLs, or package internals.
