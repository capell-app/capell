# Capell Core

![Capell Core architectural cutaway showing Site, Language, Page, URL, Settings, Theme, and Extension layers](docs/assets/readme/hero.jpg)

[![Latest Release](https://img.shields.io/github/v/release/capell-app/core?style=flat-square&label=release)](https://github.com/capell-app/core/releases/latest)
[![Latest Version on Packagist](https://img.shields.io/packagist/v/capell-app/core.svg?style=flat-square)](https://packagist.org/packages/capell-app/core)
[![Tests](https://github.com/capell-app/capell/actions/workflows/test-full.yml/badge.svg?branch=main)](https://github.com/capell-app/capell/actions/workflows/test-full.yml)
[![PHP Quality](https://github.com/capell-app/capell/actions/workflows/code-quality-and-styling.yml/badge.svg?branch=main)](https://github.com/capell-app/capell/actions/workflows/code-quality-and-styling.yml)
[![Coverage](https://codecov.io/gh/capell-app/capell/branch/main/graph/badge.svg?component=core)](https://app.codecov.io/gh/capell-app/capell?components%5B0%5D=core&displayType=list)
[![PHP](https://img.shields.io/badge/PHP-8.4-777BB4?style=flat-square&logo=php&logoColor=white)](#requirements-and-support-policy)
[![Laravel](https://img.shields.io/badge/Laravel-12.41%2B%20%7C%2013-FF2D20?style=flat-square&logo=laravel&logoColor=white)](#requirements-and-support-policy)
[![Documentation](https://img.shields.io/badge/docs-docs.capell.app-blue?style=flat-square)](https://docs.capell.app)

`capell-app/core` is the platform package for Capell CMS. It owns the shared content model, package registry, install and upgrade orchestration, settings infrastructure, and extension contracts used by Admin, Frontend, Installer, Marketplace, and first-party add-ons.

Use this package when a Laravel app needs Capell's domain records and extension API. It is not an editor UI or public renderer by itself.

## Package Boundary

Core owns:

- sites, domains, languages, pages, page URLs, layouts, themes, blueprints, media records, redirects, package state, and upgrade log records
- install, upgrade, rollback, package cache, component cache, doctor, faker, and maker commands
- package manifest validation, package registry state, settings schemas, subscribers, render blocks, maker registration, and model-level contracts
- database migrations and settings migrations for the shared Capell schema

Core does not own:

- the Filament admin panel, resources, dashboard surfaces, and editor workflow; that is `capell-app/admin`
- public request handling and public HTML rendering; that is `capell-app/frontend`
- browser installer routes and setup removal; that is `capell-app/installer`
- catalogue browsing, account linking, domain verification, and install authorization; that is `capell-app/marketplace`
- visual layout building, frontend authoring, generated HTML cache, SEO, blog, navigation, or migration/recovery features; those live in add-on packages

## Install

For a guided full-stack setup, require `capell-app/installer` and run `php artisan capell:install` — it brings in core and composer-requires the admin/frontend packages you choose. To use core on its own (headless or manual setups):

```bash
composer require capell-app/core
php artisan capell:install
```

`capell:install` coordinates the foundation install flow. On an existing Capell app, use:

```bash
php artisan capell:upgrade
php artisan capell:doctor
```

Use `php artisan list capell` in the host app to confirm the exact command set available after Composer discovery.

## Runtime Surfaces

- Provider: `Capell\Core\Providers\CapellServiceProvider`
- Config: `config/capell.php`, `config/redirects.php`
- Main models: `Page`, `PageUrl`, `Site`, `SiteDomain`, `Language`, `Layout`, `Theme`, `Blueprint`, `Type`, `Media`, `CapellExtension`, `UpgradeLogEntry`
- Main commands: `capell:install`, `capell:upgrade`, `capell:rollback`, `capell:doctor`, `capell:package-cache`, `capell:package-cache:clear`, `capell:publish-migrations`, `capell:delete-migrations`, `capell:publish-components`, `capell:make-*`
- Test case support: `Capell\Core\Testing\ExtensionTestHarness`

`Type` remains present for compatibility while the admin surface is moving toward Blueprint naming. New docs and UI copy should prefer Blueprint unless they are documenting a compatibility API that still uses type terminology.

## Extension Points

Use these extension points instead of patching first-party models or providers:

| Need                                       | Extension point                                                 |
| ------------------------------------------ | --------------------------------------------------------------- |
| Register a page subject type               | `CapellCore::registerPageType(new PageTypeData(...))`           |
| Register package settings                  | `SettingsSchemaRegistry::register()`                            |
| Register renderable definitions            | `RenderableRegistry::register()`                                |
| Subscribe to lifecycle events              | `SubscriberManager::subscribe()`                                |
| Register cache dependencies                | `CacheInvalidationRegistry::registerDependency()`               |
| Register Tailwind source/import metadata   | `TailwindAssetsRegistry::registerSource()` / `registerImport()` |
| Create package files from project patterns | `capell:make-*` maker commands                                  |

When adding a Core migration, also append it to `src/Concerns/HasMigrations.php`; otherwise package installs can miss the migration.

## Data And Persistence

Core is schema-owning. It creates the tables used by most Capell packages, including page, site, language, layout, theme, blueprint, media, extension, redirect, and upgrade state.

Settings migrations are part of package installation and must be idempotent. Wrap new settings migrations in existence checks so upgrades and fresh installs behave the same way.

Core records are used by public rendering and admin workflows, so avoid adding admin-only assumptions to models, casts, or public serialization paths.

## Verification

From the split repository root, with development dependencies installed, run the smallest relevant check first:

```bash
vendor/bin/pest tests
```

For shared contract changes, also run the package boundary and manifest tests:

```bash
vendor/bin/pest tests/Arch tests/Unit/Manifest
```

## Requirements And Support Policy

| Surface                    | Supported versions                                             |
| -------------------------- | -------------------------------------------------------------- |
| PHP                        | `^8.4` with `ext-intl`                                         |
| Laravel                    | `^12.41.1` or `^13.0`                                          |
| Filament support           | `^5.6.8 <5.7.0-beta`                                           |
| Symfony filesystem/process | `^7.2` or `^8.0`                                               |
| Symfony HTML sanitizer     | `^7.0` or `^8.0`                                               |
| Runtime                    | PHP-FPM; Laravel Octane with Swoole, RoadRunner, or FrankenPHP |

Each Capell 1.x minor receives security fixes for 24 months from its release date, and the latest 1.x minor is always supported. Upgrade all installed Capell foundation packages together to the same supported release before requesting a fix. See the [Capell security policy](https://github.com/capell-app/capell/security/policy) for vulnerability reporting.

Support covers the dependency ranges above. When an upstream PHP, Laravel, Filament, or Symfony release reaches its own end of life earlier, upgrading that dependency may be required to receive a safe fix.

## Troubleshooting

- Missing package surfaces usually mean Composer discovery or the Capell package cache is stale. Run `composer dump-autoload`, then `php artisan capell:package-cache:clear`.
- New migrations that work in tests but not on install are usually missing from `HasMigrations::getMigrations()`.
- Missing default page records or Blueprint warnings should be checked with `php artisan capell:doctor` before changing seed or setup code.
- Do not document moved features as Core behavior. Publishing Studio, generated HTML cache, site discovery, frontend authoring, SEO, blog, navigation, and Migration Assistant workflows are package-owned.

## Development

Package development and coordinated verification happen in the [capell-app/capell monorepo](https://github.com/capell-app/capell). Split package repositories are release mirrors; use [docs.capell.app](https://docs.capell.app) for cross-package guidance. See the [contribution guide](https://github.com/capell-app/capell/blob/main/CONTRIBUTING.md), [security policy](https://github.com/capell-app/capell/security/policy), and [licence](https://github.com/capell-app/capell/blob/main/LICENSE.md).

## Further Reading

| Page                                                             | Covers                                                    |
| ---------------------------------------------------------------- | --------------------------------------------------------- |
| [Core overview](docs/overview.md)                                | Core responsibilities and the package docs index.         |
| [Page management](docs/page-management.md)                       | Pages, URLs, types, and publishing state.                 |
| [Content management](docs/content-management.md)                 | Shared content records and ownership boundaries.          |
| [Extending Capell](docs/extending-capell.md)                     | Core contracts and extension surfaces.                    |
| [Cache](docs/cache.md)                                           | Shared cache helpers and invalidation behavior.           |
| [Multi-site and multi-lingual](docs/multi-site-multi-lingual.md) | Sites, domains, languages, and localized URLs.            |
| [Relationship diagnostics](docs/relationship-diagnostics.md)     | Debug missing active site domains for page URL rendering. |
| [Subscriber manager](docs/subscriber-manager.md)                 | Lifecycle subscription registration.                      |
| [Static-site extensions](docs/static-site-extensions.md)         | Static export integration points.                         |
| [Authoring upgrade steps](docs/authoring-upgrade-steps.md)       | Upgrading packages that integrate authoring behavior.     |
| [Install debugging](docs/install-debugging.md)                   | Common install and setup failures.                        |

The complete integration and extension guides are published at [docs.capell.app](https://docs.capell.app).
