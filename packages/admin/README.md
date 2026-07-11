# Capell Admin

![Capell Admin dashboard screenshot](docs/images/screenshots/admin-dashboard.png)

[![Latest Version on Packagist](https://img.shields.io/packagist/v/capell-app/admin.svg?style=flat-square)](https://packagist.org/packages/capell-app/admin)
[![Documentation](https://img.shields.io/badge/docs-docs.capell.app-blue?style=flat-square)](https://docs.capell.app)

`capell-app/admin` is the Filament panel package for Capell CMS. It adds the authenticated editor and operator surface for managing Core records, settings, package state, dashboards, permissions, and admin extension points.

Use this package when a Capell install needs a back-office UI. It depends on `capell-app/core` and should not be used to render public frontend output.

## Package Boundary

Admin owns:

- the Filament panel plugin, resources, pages, dashboard Filament widgets, actions, form/table components, policies, and admin routes
- editor workflows for pages, sites, languages, layouts, themes, blueprints, media, redirects, users, roles, settings, package state, and upgrades
- admin extension points for contributed resources, pages, widgets, header tools, form schemas, table queries, relation managers, and validation hooks
- admin settings migrations and admin-specific cache commands

Admin does not own:

- shared content records and migrations; those are Core records
- public request handling or public HTML safety; that is Frontend and frontend add-ons
- marketplace account linking and catalogue install authorization; that is Marketplace
- migration/import execution; Admin can expose a recovery shell, but the recovery implementation is package-owned
- heavy import handlers; packages register visible import menu entries through `ImportEntryRegistry` and own the execution behind those entries

## Install

Admin depends on `capell-app/core` and is optional. The recommended path is the installer (`composer require capell-app/installer` → `php artisan capell:install`), which adds Admin when you select it. To add it manually to an existing Capell app:

```bash
composer require capell-app/admin
php artisan capell:admin-install
php artisan capell:admin-setup
```

The full foundation installer may call Admin setup for you. On existing apps, use:

```bash
php artisan capell:admin-upgrade
php artisan capell:admin-clear-cache
```

The admin entrypoint is controlled by `CAPELL_ADMIN_PATH`; `CAPELL_ADMIN_DOMAIN` can move the panel to a dedicated host. Clear config cache after changing either value.

## Runtime Surfaces

- Provider: `Capell\Admin\Providers\AdminServiceProvider`
- Config: `packages/admin/config/capell-admin.php`
- Entrypoint helper: `Capell\Admin\Support\AdminPanelEntrypoint`
- Routes: `packages/admin/routes/web.php`
- Main resources: `ActivityResource`, `BlueprintResource`, `LanguageResource`, `LayoutResource`, `MediaResource`, `PageResource`, `PageUrlResource`, `RedirectResource`, `SiteResource`, `ThemeResource`, `UserResource`
- Main pages: `ExtensionsPage`, `SettingsPage`, `SiteHealthPage`, `SitemapPage`, `UpgradePage`
- Main commands: `capell:admin-install`, `capell:admin-setup`, `capell:admin-upgrade`, `capell:admin-clear-cache`, `capell:admin-cache-widgets`, `capell:admin-cache-configurators`

`ActivityResource` includes a detail view that expands nested JSON changes into readable before-and-after rows for audit review.

Admin routes include a signed theme preview route and authenticated internal API routes under the configured admin path. Theme preview URLs are temporary admin URLs with `signed` middleware, site access checks, and `no-store` response headers; do not embed them into public output, cached HTML, or long-lived editor content.

## Extension Points

Use the documented extension points instead of extending Filament resources directly:

| Need                                                     | Extension point                                              |
| -------------------------------------------------------- | ------------------------------------------------------------ |
| Contribute a resource or page                            | `CapellAdmin::contributeToAdminSurface(...)`                 |
| Register a dashboard Filament widget                     | `CapellAdmin::registerDashboardFilamentWidget(...)`          |
| Add page form fields                                     | `PageSchemaExtender::TAG`                                    |
| Add site form fields                                     | `SiteSchemaExtender::TAG`                                    |
| Add admin toolbar actions                                | `AdminToolItem::TAG`                                         |
| Adjust tables, edit pages, exports, or relation managers | the matching tagged extender interface                       |
| Add settings UI                                          | `SettingsSchemaRegistry::register()` from the owning package |

Do not run `php artisan capell:admin-publish-schemas` for normal extension work. It copies framework-owned schema files into the app and makes upgrades harder.

## Data And Permissions

Admin operates mostly on Core models. Admin-owned persistence is limited to settings migrations and admin-specific operational state.

Policy and permission behavior must be registered globally, not only through a Filament resource. Marketplace contributes additional permission-aware surfaces when it is installed.

All editor-facing labels, notifications, and validation messages should use package translation files. Prefer Filament label method overrides over static label properties.

## Verification

Run Admin package tests after changing resources, policies, settings schemas, or panel registration:

```bash
vendor/bin/pest packages/admin/tests --configuration=phpunit.xml
```

For resource or schema changes, include the matching focused test file first. For UI behavior, verify the real Filament panel with a disposable admin account rather than stopping at the login screen.

Screenshot capture is run from the monorepo root:

```bash
npm run screenshots
npm run screenshots:check
```

## Troubleshooting

- Missing navigation usually means the resource/page contribution was not registered, a permission blocks it, or the package cache is stale.
- Missing fields in an editor form should be checked at the tagged extender and settings schema level before editing first-party resources.
- If Admin appears on the wrong host or path, check `CAPELL_ADMIN_PATH`, `CAPELL_ADMIN_DOMAIN`, and cached config.
- If theme preview stops working after an entrypoint change, regenerate preview links against the current admin host/path; signed URLs are host/path-sensitive.
- If cache commands do not affect newly added blocks or configurators, confirm the package registered its block/configurator classes and rerun the matching admin cache command.

## Further Reading

| Page                                                                                         | Covers                                                                  |
| -------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------- |
| [Admin overview](docs/overview.md)                                                           | Admin responsibilities and the package docs index.                      |
| [Admin multi-language](docs/admin-multi-language.md)                                         | Admin language records, translations, and user preferences.             |
| [Admin tool registry](docs/admin-tool-registry.md)                                           | Header tools and admin utility actions.                                 |
| [Dashboard Filament widget customization](docs/dashboard-widget-customization.md)            | Registering and overriding dashboard Filament widgets.                  |
| [Marketing Studio](docs/marketing-studio.md)                                                 | Contributing editor-focused marketing actions and widgets.              |
| [Event registry](docs/event-registry.md)                                                     | Admin lifecycle event subscriptions.                                    |
| [Permissions and approval](docs/permissions-and-approval.md)                                 | Role and approval rules around publishing.                              |
| [Resource registration](docs/resource-registration.md)                                       | Contributed resources and admin surface lookup.                         |
| [Schema hooks](docs/schemas/hooks.md)                                                        | Extending admin form schemas.                                           |
| [Settings schema registry](docs/settings-schema-registry.md)                                 | Package settings in the admin settings surface.                         |
| [User menu registry](docs/user-menu-registry.md)                                             | User menu item registration.                                            |
| [User resource customization](docs/user-resource-customization.md)                           | User form fields, panels, relation managers, and bridges.               |
| [Admin documentation index](../../docs/admin/index.md)                                       | Host-level admin setup, interface, recovery, media, and dashboard docs. |
| [Package admin extensions](../../docs/packages/admin-extensions.md)                          | Package-owned admin extension guidance.                                 |
| [Extension troubleshooting](../../docs/packages/extension-troubleshooting.md#composer-drift) | Composer drift alerts, repair command, and metadata keys.               |
