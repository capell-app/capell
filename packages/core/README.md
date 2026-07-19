# Capell Core

![Capell Core architectural cutaway showing Site, Language, Page, URL, Settings, Theme, and Extension layers](docs/assets/readme/hero.jpg)

[![Latest Release](https://img.shields.io/github/v/release/capell-app/core?style=flat-square&label=release)](https://github.com/capell-app/core/releases/latest)
[![Latest Version on Packagist](https://img.shields.io/packagist/v/capell-app/core.svg?style=flat-square)](https://packagist.org/packages/capell-app/core)
[![Tests](https://github.com/capell-app/capell/actions/workflows/test-full.yml/badge.svg?branch=main)](https://github.com/capell-app/capell/actions/workflows/test-full.yml)
[![PHP Quality](https://github.com/capell-app/capell/actions/workflows/code-quality-and-styling.yml/badge.svg?branch=main)](https://github.com/capell-app/capell/actions/workflows/code-quality-and-styling.yml)
[![PHP](https://img.shields.io/badge/PHP-8.4-777BB4?style=flat-square&logo=php&logoColor=white)](#requirements-and-support-policy)
[![Laravel Compatibility](https://badge.laravel.cloud/badge/capell-app/core?style=flat)](https://packagist.org/packages/capell-app/core)
[![Documentation](https://img.shields.io/badge/docs-docs.capell.app-blue?style=flat-square)](https://docs.capell.app)

Capell Core is the platform layer underneath every Capell CMS install. It holds the shared content model — sites, pages, URLs, themes and the rest — plus the install and upgrade machinery and the extension contracts that the other Capell packages build on. Reach for it directly when your Laravel app needs Capell's domain records and extension API without the full editor stack. On its own it gives you no admin UI and renders no public pages; those live in `capell-app/admin` and `capell-app/frontend`.

## Package boundary

Core owns:

- the shared content records: sites, domains, languages, pages, page URLs, layouts, themes, blueprints, media, redirects, package state, and upgrade logs
- the lifecycle commands: install, upgrade, rollback, package cache, component cache, doctor, faker, and maker commands
- the extension machinery: package manifest validation, registry state, settings schemas, subscribers, render blocks, maker registration, and model-level contracts
- the database migrations and settings migrations for the shared Capell schema

Core does not own:

- the Filament admin panel and editor workflow; that is `capell-app/admin`
- public request handling and public HTML rendering; that is `capell-app/frontend`
- browser installer routes and setup removal; that is `capell-app/installer`
- catalogue browsing, account linking, domain verification, and install authorisation; that is `capell-app/marketplace`
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

Run `php artisan list capell` in the host app to see the exact command set available after Composer discovery.

## Quick example

Registering a page type from a service provider is the most common first extension. This makes your own model addressable as a Capell page subject:

```php
use Capell\Core\Data\PageTypeData;
use Capell\Core\Facades\CapellCore;
use Vendor\Example\Models\LandingExperience;

public function boot(): void
{
    CapellCore::registerPageType(new PageTypeData(
        name: 'landing-experience',
        model: LandingExperience::class,
        label: 'Landing experiences',
    ));
}
```

The [extending guide](docs/extending-capell.md) walks through this and the other extension surfaces.

## Runtime surfaces

- Provider: `Capell\Core\Providers\CapellServiceProvider`
- Config: `config/capell.php`, `config/redirects.php`
- Main models: `Page`, `PageUrl`, `Site`, `SiteDomain`, `Language`, `Layout`, `Theme`, `Blueprint`, `Type`, `Media`, `CapellExtension`, `UpgradeLogEntry`
- Main commands: `capell:install`, `capell:upgrade`, `capell:rollback`, `capell:doctor`, `capell:package-cache`, `capell:package-cache:clear`, `capell:publish-migrations`, `capell:delete-migrations`, `capell:publish-components`, `capell:make-*`
- Test case support: `Capell\Core\Testing\ExtensionTestHarness`

`Type` remains present for compatibility while the admin surface moves toward Blueprint naming. Prefer Blueprint in new docs and UI copy unless you are documenting a compatibility API that still uses type terminology.

## Extension points

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

## Data and persistence

Core owns the schema. It creates the tables used by most Capell packages, including page, site, language, layout, theme, blueprint, media, extension, redirect, and upgrade state.

Settings migrations are part of package installation and must be idempotent. Wrap new settings migrations in existence checks so upgrades and fresh installs behave the same way.

Core records feed both public rendering and admin workflows, so avoid adding admin-only assumptions to models, casts, or public serialisation paths.

## Verification

Core tests run from a checkout of the Capell monorepo, which supplies the Pest bootstrap and development dependencies this package needs. From the monorepo root, run the smallest relevant check first:

```bash
vendor/bin/pest tests
```

For shared contract changes, also run the package boundary and manifest tests:

```bash
vendor/bin/pest tests/Arch tests/Unit/Manifest
```

## Requirements and support policy

| Surface                    | Supported versions                                             |
| -------------------------- | -------------------------------------------------------------- |
| PHP                        | `^8.4` with `ext-intl`                                         |
| Laravel                    | `^12.41.1` or `^13.0`                                          |
| Filament support           | `~5.6.8`                                                       |
| Symfony filesystem/process | `^7.2` or `^8.0`                                               |
| Symfony HTML sanitizer     | `^7.0` or `^8.0`                                               |
| Runtime                    | PHP-FPM; Laravel Octane with Swoole, RoadRunner, or FrankenPHP |

Each Capell 1.x minor receives security fixes for 24 months from its release date, and the latest 1.x minor is always supported. Upgrade all installed Capell foundation packages together to the same supported release before requesting a fix. See the [Capell security policy](https://github.com/capell-app/capell/security/policy) for vulnerability reporting.

Support covers the dependency ranges above. When an upstream PHP, Laravel, Filament, or Symfony release reaches its own end of life earlier, upgrading that dependency may be required to receive a safe fix.

## Troubleshooting

- Missing package surfaces usually mean Composer discovery or the Capell package cache is stale. Run `composer dump-autoload`, then `php artisan capell:package-cache:clear`.
- New migrations that work in tests but not on install are usually missing from `HasMigrations::getMigrations()`.
- Check missing default page records or Blueprint warnings with `php artisan capell:doctor` before changing seed or setup code.
- Do not document moved features as Core behaviour. Publishing Studio, generated HTML cache, site discovery, frontend authoring, SEO, blog, navigation, and Migration Assistant workflows are package-owned.

## Development

Package development and coordinated verification happen in the [capell-app/capell monorepo](https://github.com/capell-app/capell). Split package repositories are release mirrors; use [docs.capell.app](https://docs.capell.app) for cross-package guidance. See the [contribution guide](https://github.com/capell-app/capell/blob/main/CONTRIBUTING.md), [security policy](https://github.com/capell-app/capell/security/policy), and [licence](https://github.com/capell-app/capell/blob/main/LICENSE.md).

## Further reading

| Page                                                             | Covers                                                    |
| ---------------------------------------------------------------- | --------------------------------------------------------- |
| [Core overview](docs/overview.md)                                | Core responsibilities and the package docs index.         |
| [Page management](docs/page-management.md)                       | Pages, URLs, blueprints, and publishing state.            |
| [Content management](docs/content-management.md)                 | Shared content records and ownership boundaries.          |
| [Extending Capell](docs/extending-capell.md)                     | Core contracts and extension surfaces.                    |
| [Cache](docs/cache.md)                                           | Shared cache helpers and invalidation behaviour.          |
| [Multi-site and multi-lingual](docs/multi-site-multi-lingual.md) | Sites, domains, languages, and localised URLs.            |
| [Relationship diagnostics](docs/relationship-diagnostics.md)     | Debug missing active site domains for page URL rendering. |
| [Subscriber manager](docs/subscriber-manager.md)                 | Lifecycle subscription registration.                      |
| [Static-site extensions](docs/static-site-extensions.md)         | Static export integration points.                         |
| [Authoring upgrade steps](docs/authoring-upgrade-steps.md)       | Upgrading packages that integrate authoring behaviour.    |
| [Install debugging](docs/install-debugging.md)                   | Common install and setup failures.                        |

The complete integration and extension guides are published at [docs.capell.app](https://docs.capell.app).
