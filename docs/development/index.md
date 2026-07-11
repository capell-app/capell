# Development

![Capell Development screenshot](../images/admin-dashboard.png)

Use this page when working in the Capell 4.x host repo or wiring Capell into a Laravel app. Product concepts live in [How Capell works](../getting-started/how-capell-works.md); package-authoring rules live in [Packages](../packages/README.md).

> **Who's this for?** Contributors working inside this monorepo. Local setup, commands, and tests — not needed to install or use Capell ([start here](../getting-started/quickstart.md)).

## Repository Shape

Local 4.x work normally uses two sibling repos:

```text
capell/
    capell-4/
    capell-packages-4/
```

The host repo owns Core, Admin, Frontend, Installer, and Marketplace. Add-on package behavior belongs in `../capell-packages-4` unless the host repo owns the contract or extension point.

Use Composer path repositories for local package development. Keep matching `4.x` branches in both repos when a change spans host and add-on packages.

## Daily Commands

| Command                  | Use                                                                                      |
| ------------------------ | ---------------------------------------------------------------------------------------- |
| `composer prepare`       | Run Testbench package discovery.                                                         |
| `composer test:fast`     | Run the sharded fast Pest command while developing.                                      |
| `composer test`          | Run the full Pest test suite.                                                            |
| `composer lint`          | Run changed-file Pint formatting.                                                        |
| `composer analyze`       | Run the fast PHPStan configuration.                                                      |
| `composer preflight`     | Run fast PHPStan and changed-file formatting.                                            |
| `composer preflight:all` | Run Composer path checks, doc guards, Rector, Pint, Prettier, ESLint, PHPStan, and Pest. |
| `composer serve`         | Build and serve the Testbench workbench.                                                 |

Start narrow while developing:

```bash
vendor/bin/pest packages/admin/tests --configuration=phpunit.xml
vendor/bin/pest packages/frontend/tests/Unit/Security/PublicHtmlSafetyInspectorTest.php --configuration=phpunit.xml
```

Use the [command index](commands.md) for a short grouped reference and [artisan commands](artisan-commands.md) when you need install option details.

## Capell Artisan Commands

Check the installed app first:

```bash
php artisan list capell
```

Common host commands:

| Command                                                                                                                                                 | Use                                                                                  |
| ------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------ |
| `capell:install`                                                                                                                                        | Install Capell packages, default data, admin access, and selected optional packages. |
| `capell:upgrade`                                                                                                                                        | Run host package upgrade flow and migrations.                                        |
| `capell:doctor`                                                                                                                                         | Run host diagnostic checks.                                                          |
| `capell:extension-audit`                                                                                                                                | Validate extension contracts and manifests.                                          |
| `capell:extensions:repair-composer-drift`                                                                                                               | Repair Composer-actionable extension drift outside dashboard requests.               |
| `capell:package-cache` / `capell:package-cache:clear`                                                                                                   | Warm or clear package discovery cache.                                               |
| `capell:make`, `capell:make-action`, `capell:make-data`, `capell:make-extender`, `capell:make-extension`, `capell:make-schema`, `capell:make-blueprint` | Generate host/app/package scaffolding.                                               |
| `capell:admin-install`, `capell:admin-setup`, `capell:admin-upgrade`                                                                                    | Admin package install/setup/upgrade surfaces.                                        |
| `capell:admin-cache-widgets`, `capell:admin-clear-widgets-cache`, `capell:admin-cache-configurators`, `capell:admin-clear-configurators-cache`          | Warm or clear Admin registries.                                                      |
| `capell:frontend-install`, `capell:frontend-after-install`, `capell:frontend-upgrade`                                                                   | Frontend package lifecycle commands.                                                 |

Optional-package commands such as `capell:static-site`, `capell:xml-sitemap`, and `capell:frontend-tailwind-assets` only exist when their package is installed.

## Development Routes

| Need                                    | Read                                                   |
| --------------------------------------- | ------------------------------------------------------ |
| Decide host vs package vs app ownership | [Host, package, or app code](package-boundaries.md)    |
| Pick an install path                    | [Install matrix](../getting-started/install-matrix.md) |
| Configure local repo work               | [Local development](local-development.md)              |
| Work with the 4.x monorepo branch       | [Monorepo 4.x branch](monorepo-4x-branch.md)           |
| Run Capell in containers                | [Container development](container-development.md)      |
| Install or repair admin setup           | [Admin install setup](admin-install-setup.md)          |
| Seed content safely                     | [Seeding content](seeding-content.md)                  |
| Add settings migrations                 | [Settings migrations](settings-migrations.md)          |
| Work with public page API output        | [Public page API](public-page-api.md)                  |
| Understand CI and Pest shards           | [CI and test shards](ci.md)                            |
| Measure Blade view test coverage        | [Blade view coverage](blade-view-coverage.md)          |
| Look up config keys and env vars        | [Configuration reference](configuration.md)            |
| Create package authoring surfaces       | [Package authoring](../platform/package-authoring.md)  |
| Diagnose environment or registry state  | [Diagnostics](diagnostics.md)                          |
| Decide where docs belong                | [Docs ownership rules](docs-ownership.md)              |
| Avoid unsafe extension/package patterns | [Do not do this](do-not-do-this.md)                    |
| Capture useful docs screenshots         | [Screenshot state guide](screenshot-state-guide.md)    |

## Configuration

Host config lives in:

| File                                                 | Covers                                                                                                                      |
| ---------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------- |
| `packages/core/config/capell.php`                    | Package discovery, cache flags, media backend, install tooling, roles, workspace preview/prune, release windows.            |
| `packages/admin/config/capell-admin.php`             | Admin settings, update notices, cache refresh, security headers, preview containers.                                        |
| `packages/frontend/config/capell-frontend.php`       | Asset build tool, home route registration, fallback site behavior, model-event registration, debug logging, HTML rendering. |
| `packages/installer/config/capell-installer.php`     | Reinstall guard, Composer/PHP binaries, default install package selection.                                                  |
| `packages/marketplace/config/capell-marketplace.php` | Marketplace enablement, API/web URLs, identity, webhook URL/secret, catalogue paging.                                       |

After changing config in an app:

```bash
php artisan optimize:clear
```

## Seeding Content

Use Actions and models directly from seeders. Avoid building seeders around admin forms.

- Create sites with `CreateSiteAction`.
- Create pages with `CreatePageAction` or page factories in tests.
- Use `PageUrl` records for language/site-aware URLs.
- Seed translations explicitly.
- Use package seeders for optional package data.

For repeatable demos, keep demo content in the package that owns the feature.

## Settings Migrations

Settings migrations go in `database/settings/`, are registered by install/setup commands, and must be guarded with existence checks. Do not assume a settings table exists during early install or test bootstrap.

## Developer Tools

Use maker commands for boring scaffolding, then write the real work by hand.

- Reach for `capell:make-*` to stamp out the file and wiring.
- Write domain behavior as explicit Actions, and structured boundaries as Data objects.

Diagnostics should report state without mutating it. If a diagnostic can fix something, make the repair an explicit Action and call it from a separate command or admin action.
