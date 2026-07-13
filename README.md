# Capell CMS

![Capell CMS banner showing the Pages admin surface](docs/images/capell-readme-banner.jpg)

[![Latest Release](https://img.shields.io/github/v/release/capell-app/capell?style=flat-square&label=release)](https://github.com/capell-app/capell/releases/latest)
[![Tests](https://github.com/capell-app/capell/actions/workflows/test-full.yml/badge.svg?branch=main)](https://github.com/capell-app/capell/actions/workflows/test-full.yml)
[![PHP Quality](https://github.com/capell-app/capell/actions/workflows/code-quality-and-styling.yml/badge.svg?branch=main)](https://github.com/capell-app/capell/actions/workflows/code-quality-and-styling.yml)
[![Coverage](https://codecov.io/gh/capell-app/capell/branch/main/graph/badge.svg)](https://app.codecov.io/gh/capell-app/capell)
[![PHP](https://img.shields.io/badge/PHP-8.4-777BB4?style=flat-square&logo=php&logoColor=white)](https://www.php.net/releases/8.4/en.php)
[![Laravel](https://img.shields.io/badge/Laravel-12.41%2B%20%7C%2013-FF2D20?style=flat-square&logo=laravel&logoColor=white)](#requirements)
[![Documentation](https://img.shields.io/badge/docs-docs.capell.app-blue?style=flat-square)](https://docs.capell.app)

**Capell is a CMS layer for Laravel, built on Filament.** It adds pages, multi-site and multi-language URLs, media, redirects, roles, settings, and an editor workspace to your application. Your team still owns the public frontend and renders it with Blade, Livewire, Inertia, Vue, static HTML, or another Laravel-compatible stack.

## Start Here

| Task                                      | Start with                                                                                                                                                                                   |
| ----------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Try Capell in a fresh Laravel application | [Quickstart](docs/getting-started/quickstart.md)                                                                                                                                             |
| Install it in an existing application     | [Install guide](docs/getting-started/install.md)                                                                                                                                             |
| Learn the concepts and architecture       | [Capell Learn](docs/getting-started/capell-learn.md), then [How Capell works](docs/getting-started/how-capell-works.md)                                                                      |
| Build or extend a package                 | [Package authoring](docs/packages/README.md)                                                                                                                                                 |
| Onboard an editor                         | [First editor session](docs/getting-started/first-session.md)                                                                                                                                |
| Build the public frontend                 | [Build a page](docs/getting-started/building-pages.md), [use Inertia and Vue](docs/getting-started/inertia-runtime.md), or [add live fragments](docs/getting-started/capell-interactions.md) |
| Contribute to this repository             | [Contribution guide](CONTRIBUTING.md)                                                                                                                                                        |

## See Capell In Practice

Capell's Filament workspace keeps page trees, media, settings, theme workflows, diagnostics, and package-backed tools inside the Laravel application you deploy.

![Capell admin dashboard](docs/images/admin-dashboard.png)

See the [admin interface](docs/admin/interface.md), follow the [first-page walkthrough](docs/getting-started/create-your-first-page.md), or inspect the [music store CMS example](docs/examples/music-store-cms.md).

## Quick Local Demo

The foundation packages are public on Packagist, so this disposable demo does not need private Composer credentials:

```bash
composer create-project laravel/laravel music-store
cd music-store
composer require capell-app/installer
php artisan filament:install --panels
php artisan capell:install --demo --url=http://localhost:8000
php -S 127.0.0.1:8000 -t public public/index.php
```

Open `http://localhost:8000/admin` for the admin panel and `http://localhost:8000` for the frontend.

## What This Repository Contains

This 4.x host monorepo contains the five foundation packages. First-party features such as themes, SEO, and Publishing Studio live in separate Composer packages listed in the [package catalogue](docs/packages/catalog.md).

| Package     | Composer name            | What it owns                                                                                                 | Docs                                              |
| ----------- | ------------------------ | ------------------------------------------------------------------------------------------------------------ | ------------------------------------------------- |
| Core        | `capell-app/core`        | The content model: sites, languages, pages, URLs, layouts, themes, media, translations, settings, registries | [Overview](packages/core/docs/overview.md)        |
| Admin       | `capell-app/admin`       | The Filament editor workspace: resources, dashboards, settings, media, users, admin extension points         | [Overview](packages/admin/docs/overview.md)       |
| Frontend    | `capell-app/frontend`    | Public routing, site context, themes, assets, render hooks, response delivery, optional cache/static output  | [Overview](packages/frontend/docs/overview.md)    |
| Installer   | `capell-app/installer`   | The browser installer and installer cleanup flow                                                             | [Overview](packages/installer/docs/overview.md)   |
| Marketplace | `capell-app/marketplace` | Extension discovery, install authorization, and package acquisition                                          | [Overview](packages/marketplace/docs/overview.md) |

## Installing

Foundation packages install from public Packagist repositories. Paid marketplace packages require authenticated Composer access and an active entitlement. Current releases require PHP 8.4, Laravel 12.41.1+ or 13.x, and Filament `^5.6.8 <5.7.0-beta`.

### Recommended — the installer

Require the installer and run the guided flow. It adds `capell-app/core`, then `capell:install` requires the selected admin and frontend packages and writes them to the application's `composer.json`. The installer can be removed after setup.

```bash
composer require capell-app/installer
php artisan capell:install
```

### Manual — require packages directly

Skip the installer to choose packages directly. `capell-app/core` is the only hard dependency; `admin` and `frontend` are optional and each depend on core.

```bash
# Full stack, no installer
composer require capell-app/core capell-app/admin capell-app/frontend -W

# Core only, without Admin or Frontend
composer require capell-app/core -W

# Core + admin, no public frontend
composer require capell-app/core capell-app/admin -W
```

Then run `php artisan capell:install` to apply migrations and setup; pass `--packages=` to scope it (for example `--packages=capell-app/admin`). `capell-app/capell` is the host monorepo repository, not an install target.

## How Capell Works

Capell keeps content, editing, and public delivery in separate packages:

- **Core** owns the reusable content model and registries that packages extend.
- **Admin** gives editors a Filament workspace over that model.
- **Frontend** connects the model to the public site through routing, site context, themes, assets, render hooks, and response delivery, then hands the final HTML to your application.
- **Packages** add fields, widgets, integrations, themes, workflows, and tools as normal Laravel packages.

Domain writes go through **Actions**, and structured boundary state uses **Data** objects. Business rules stay out of Filament resources, Livewire components, controllers, and templates.

Anonymous and non-admin HTML must never leak authoring markup, model IDs, selectors, signed editor URLs, or package internals. See the [public HTML safety contract](docs/frontend/public-html-safety.md).

## Extension Points

Packages and apps add behaviour through registries instead of patching host classes:

| Surface                  | Entry point                                                                                                 |
| ------------------------ | ----------------------------------------------------------------------------------------------------------- |
| Page types               | `CapellCore::registerPageType(...)`                                                                         |
| Admin surfaces & widgets | `CapellAdmin::contributeToAdminSurface(...)`, `registerDashboardFilamentWidget(...)`, `registerWidget(...)` |
| Form field schemas       | tagged extenders such as `PageSchemaExtender::TAG`                                                          |
| Settings                 | `SettingsSchemaRegistry::register(...)`                                                                     |
| Frontend render hooks    | `RenderHookRegistry::register(...)`                                                                         |
| Cache dependencies       | `CacheInvalidationRegistry::registerDependency(...)`                                                        |

Use the [extension point chooser](docs/packages/extension-point-chooser.md) or [API reference](docs/packages/extension-point-api-reference.md) for the complete contracts.

## Requirements

| Tool     | Supported versions                                                   |
| -------- | -------------------------------------------------------------------- |
| PHP      | 8.4+                                                                 |
| Laravel  | 12.41.1+ or 13.x                                                     |
| Filament | 5.6.8+ below 5.7 (`^5.6.8 <5.7.0-beta`)                              |
| Database | MySQL 8+, MariaDB 10.3+, SQLite, or your configured Laravel database |
| Node.js  | 20+                                                                  |
| Composer | 2.7+                                                                 |
| Runtime  | PHP-FPM or Laravel Octane (Swoole, RoadRunner, FrankenPHP)           |

See the [install guide](docs/getting-started/install.md) for required PHP extensions, permissions, and install paths.

For the shipped 1.x line, each minor receives security fixes for 24 months from its release date, and the latest 1.x minor is always supported. See the [security policy](SECURITY.md) for vulnerability reporting and the [Core support policy](packages/core/README.md#requirements-and-support-policy) for exact package requirements.

## Contributing To This Repository

These commands are for work inside this monorepo:

Common Composer scripts:

| Command                  | Purpose                                                                                  |
| ------------------------ | ---------------------------------------------------------------------------------------- |
| `composer test`          | Run the Pest test suite                                                                  |
| `composer test:fast`     | Run the sharded fast Pest command                                                        |
| `composer lint`          | Run changed-file Pint formatting                                                         |
| `composer analyze`       | Run the fast PHPStan configuration                                                       |
| `composer preflight`     | Run the fast quality and test gate (PHPStan, Rector, formatting, ESLint, preflight Pest) |
| `composer preflight:all` | Full non-mutating quality gate (checks, PHPStan, audit, Pest)                            |
| `composer serve`         | Build and serve the Testbench workbench at localhost                                     |

A Docker harness is available when you need a clean shell for agent, CI, or local verification (`docker compose up -d`, then `docker compose exec app composer test`). It is a CLI package-development harness, not an application runtime, and provides MariaDB, Redis, Mailpit, Node, and the required PHP extensions.

For local path-repository setup, branch guidance, and the contribution workflow, see [CONTRIBUTING.md](CONTRIBUTING.md) and the [Development docs](docs/development/index.md).

## License, Pricing & Support

Capell is commercial software (`"license": "proprietary"`) with public foundation source and Composer distribution. Public visibility does not change the Capell licence. Each licensed copy may run in one production environment at a time, and the licence does not include updates or support unless those are part of the commercial agreement. See [LICENSE.md](LICENSE.md) for the full terms.

The public foundation installs from Packagist. Capell All Access — Complete Collection is £199 GBP for twelve months of approved first-party extensions, themes, updates and support; protected packages require an active entitlement. See [Capell pricing](https://capell.app/pricing) for the current scope, renewal and refund terms.

- **Questions and discussion:** use the customer contact path or the relevant public repository issue tracker. Customer-specific entitlement and support questions belong in the Capell account support flow.
- **Documentation:** [docs.capell.app](https://docs.capell.app)
- **Security:** report privately via [SECURITY.md](SECURITY.md) — do not open a public issue for an undisclosed vulnerability.
