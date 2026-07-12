# Packages

Capell packages extend the CMS without adding feature code to Core, Admin, or Frontend. Use a package when a capability can be installed, versioned, tested, and disabled independently.

Use this section if you build or maintain a Capell package.

| I need to...                               | Read                                                               |
| ------------------------------------------ | ------------------------------------------------------------------ |
| Decide between host, package, and app code | [Host, package, or app code](../development/package-boundaries.md) |
| Build a package from start to finish       | [Build an extension end to end](build-extension-end-to-end.md)     |
| Find the shortest path for a package task  | [Package authoring jobs](package-authoring-jobs.md)                |
| Understand package files and structure     | [Package anatomy](package-anatomy.md)                              |
| Choose an extension point                  | [Extension point chooser](extension-point-chooser.md)              |
| Add admin surfaces                         | [Admin extensions](admin-extensions.md)                            |
| Add anonymous-safe frontend output         | [Frontend extensions](frontend-extensions.md)                      |
| Look up exact contracts, tags, and tests   | [Extension point API reference](extension-point-api-reference.md)  |
| Test a package                             | [Testing packages](testing-packages.md)                            |
| Debug missing package output               | [Extension troubleshooting](extension-troubleshooting.md)          |

Use [Extension surface vocabulary](extension-surface-vocabulary.md) when you need the shared definitions for packages, surfaces, contributions, capabilities, install impact, and Marketplace proof.

## When To Create A Package

| Package is right when...                                                                | Keep it in the app when...                      |
| --------------------------------------------------------------------------------------- | ----------------------------------------------- |
| The feature has its own settings, routes, resources, widgets, jobs, or frontend output. | It is one-off project glue with no reuse value. |
| It should be installable through Composer or Marketplace.                               | It depends heavily on private app models.       |
| It needs its own tests and release cadence.                                             | The behavior is just app configuration.         |
| It contributes through Capell extension points.                                         | It needs to patch host package classes.         |

If you are unsure, use [Host, package, or app code](../development/package-boundaries.md).

## Required Files

Every package should have:

- `composer.json`
- `capell.json`
- at least one service provider
- `README.md`
- translations for visible labels
- focused package tests

Packages with admin UI usually also include translations, Filament resources/pages/widgets, settings schemas, an admin bridge, and feature tests.

## Create A Package

Use `capell:make-extension` for new local package scaffolds. The command keeps the existing extension command name, but the generated files use package language and manifest v3 provider buckets.

Interactive mode asks for missing values:

```bash
php artisan capell:make-extension
```

Non-interactive scripts must pass the package name, profile, and target directory:

```bash
php artisan capell:make-extension vendor/example --profile=minimal --path=packages --name="Example"
php artisan capell:make-extension vendor/example-tools --profile=full --path=packages --premium
```

Use `minimal` for a lean installable package with Composer metadata, `capell.json`, one runtime provider, translations, README, and manifest/safety tests. Use `full` when you want live examples for provider buckets, package-owned commands, settings, safe frontend render hooks, Actions, Data, and public-output tests.

## Install A Package In An App

Capell packages are Composer packages. For a published package:

```bash
composer require vendor/example
php artisan capell:package-cache:clear
```

For local development, add a Composer path repository to the app for the package path you are editing:

```bash
composer config repositories.vendor-example path packages/example
composer require vendor/example:@dev
```

Use a wildcard path repository in `composer.json` when the app should load several local packages:

```json
{
    "repositories": [
        {
            "type": "path",
            "url": "packages/*",
            "options": {
                "symlink": true
            }
        }
    ]
}
```

See [How to create a Capell extension](how-to-create-a-capell-extension.md#5-require-the-package-with-composer) for Packagist, private Git, and Marketplace examples.

## `capell.json`

Keep the manifest boring and explicit. It should identify the package, provider classes, version constraints, contribution metadata, settings ownership, lifecycle commands, and Marketplace metadata when relevant.

Use package-owned manifests and generated extension pages as the source of truth for public add-on package names. Use [Packages and extensions](catalog.md) for host package boundaries and authoring entry points.

## Service Providers

Package providers should register only what the package owns:

- config and translations
- migrations and settings migrations
- package settings classes
- admin bridges and admin surface contributions
- render hooks, assets, frontend widgets, and cache invalidation rules
- routes, commands, policies, events, and jobs

Do not register optional-package behavior from the host repo. The package that owns the feature should register it.

Use the [extension point chooser](extension-point-chooser.md) before adding a new registration path. Use the [extension point API reference](extension-point-api-reference.md) when you need exact contracts, tags, and test recipes. Use [extension troubleshooting](extension-troubleshooting.md) when a package is installed but its admin, frontend, settings, cache, or marketplace contribution does not appear.

Installer and Marketplace surfaces have their own narrow contracts:

- [Installer extension contracts](installer-extension-contracts.md)
- [Marketplace extension contracts](marketplace-extension-contracts.md)

## Actions, Data, And Settings

- Put domain behavior in `src/Actions/*Action.php`.
- Put structured inputs/outputs in `src/Data/*Data.php`.
- Cast JSON settings/state to Data objects where practical.
- Keep Filament resources, Livewire components, controllers, commands, and jobs thin.
- Use translations for visible strings.

Command, resource, or job tests should fake Actions when the Action already has direct coverage.

## Admin Contributions

Deciding between an [`AdminBridge`](../admin/admin-bridges.md) and a direct contribution:

- Contributing several admin concerns (resources + widgets + settings)? Use an `AdminBridge`.
- Just one surface? A direct `CapellAdmin::contributeToAdminSurface(...)` call is fine.
- Unsure? Prefer a bridge. One class is easier to audit than scattered calls.

Use an `AdminBridge` when a package contributes more than one admin concern:

```php
final class PackageAdminBridge implements AdminBridge
{
    public function register(AdminBridgeRegistrar $registrar, AdminBridgeContextData $context): void
    {
        $registrar->resource(ExampleResource::class, group: 'content', name: 'examples');
        $registrar->widget(ExampleWidget::class);
        $registrar->settingsClass('examples', ExampleSettings::class);
        $registrar->settingsSchema('examples', ExampleSettingsSchema::class);
    }
}
```

Small packages can contribute directly with `CapellAdmin::contributeToAdminSurface(...)`, but bridges are easier to audit.

Useful admin surfaces:

| Need                                       | Use                                                                    |
| ------------------------------------------ | ---------------------------------------------------------------------- |
| Filament page/resource/widget/configurator | `AdminSurfaceContributionData` through `AdminBridgeRegistrar`.         |
| Page, site, layout, or user form fields    | Tagged [schema extenders](../../packages/admin/docs/schemas/hooks.md). |
| Dashboard Filament widgets                 | `CapellAdmin::registerDashboardFilamentWidget(...)`.                   |
| Header tools                               | `AdminToolItem::TAG`.                                                  |
| User menu items                            | `CapellAdmin::registerUserMenuItem(...)`.                              |
| Admin widgets                              | `CapellAdmin::registerWidget(...)`.                                    |

## Frontend Contributions

Frontend package code must preserve public HTML safety. Anonymous output must not expose authoring selectors, model IDs, field paths, signed admin URLs, permissions, or package internals.

| Need                    | Use                                                                                                   |
| ----------------------- | ----------------------------------------------------------------------------------------------------- |
| Small HTML injection    | [`RenderHookRegistry::register(...)`](../../packages/frontend/docs/extending-render-hooks.md).        |
| Public widget           | `LayoutWidgetRegistry::register(...)` with `LayoutWidgetTarget::FrontendBlade` or `FrontendLivewire`. |
| Package CSS/JS          | `TailwindAssetsRegistry::registerSource(...)` and `registerImport(...)`.                              |
| Page cache invalidation | `CacheInvalidationRegistry::registerDependency(...)`.                                                 |
| Static-site export hook | `StaticSiteExtensionRegistry::register(...)` when the static export package is installed.             |

## Database And Migrations

- Package migrations live in the package.
- Settings migrations live in `database/settings/`.
- Guard settings migrations with table/column existence checks.
- Writes go through Actions, not model methods.
- If the package introduces public rendering data, make sure controllers/actions load it before Blade renders.

## Tests

Cover package behavior where it lives:

- Action tests for domain behavior.
- Feature tests for routes, commands, Filament pages/resources, and package install/setup.
- Public rendering tests for frontend output and safety.
- Architecture tests for package boundaries.
- Manifest/provider tests so package discovery fails loudly.

When testing commands or UI surfaces that only orchestrate Actions, fake the Action boundary with Laravel Actions fakes instead of running the Action again. The surface test should prove prompts, options, output, permissions, and Action invocation; the Action test should prove domain behavior.

Use the narrowest package Pest command during development, then broaden before release:

```bash
vendor/bin/pest packages/admin/tests --configuration=phpunit.xml
```

## Release Checklist

Use the [package checklist](package-checklist.md) before release.

- `composer.json` and `capell.json` match the package name.
- Provider registers only package-owned behavior.
- Visible strings use translations.
- Actions and Data objects cover meaningful boundaries.
- Migrations are idempotent for installed apps.
- Public frontend output is safe for anonymous users.
- Package tests pass in isolation.
- README explains install, config, commands, extension points, and troubleshooting.

## Read Next

| Need                                        | Read                                                            |
| ------------------------------------------- | --------------------------------------------------------------- |
| Add ready-made page sections                | [Content Sections](content-sections.md)                         |
| Build pages visually with widgets           | [Layout Builder](layout-builder.md)                             |
| Understand package/surface/capability terms | [Extension surface vocabulary](extension-surface-vocabulary.md) |
| Write package service providers             | [Service providers](service-providers.md)                       |
| Understand the extension lifecycle          | [Extension lifecycle](extension-lifecycle.md)                   |
| Use Actions, Data, and settings correctly   | [Actions, Data, and settings](data-actions-settings.md)         |
| Ship migrations and settings tables         | [Database and migrations](database-and-migrations.md)           |
| Study worked extension examples             | [Extension examples](extension-examples.md)                     |
| Group packages into products                | [Package product groups](product-groups.md)                     |
| Scaffold packages with AI assistance        | [AI creator](ai-creator.md)                                     |
| Understand package authoring as a platform  | [Package authoring](../platform/package-authoring.md)           |
| Create an installable theme package         | [Creating custom themes](creating-custom-themes.md)             |
| Understand package boot/provider buckets    | [Package boot lifecycle](package-boot-lifecycle.md)             |
| Debug Composer/manifest/provider discovery  | [Debugging package discovery](debugging-package-discovery.md)   |
| Avoid unsafe package patterns               | [Do not do this](../development/do-not-do-this.md)              |
