# Capell CMS

![Capell CMS banner showing the Pages admin surface](docs/images/capell-readme-banner.jpg)

[![Documentation](https://img.shields.io/badge/docs-docs.capell.app-blue?style=flat-square)](https://docs.capell.app)

**Capell is a CMS layer for Laravel, built on Filament.** It gives your app the content model most teams rebuild on every project — pages, multi-site, multi-language URLs, media, redirects, roles, settings — while leaving the public frontend entirely in your hands.

You keep Laravel: Eloquent models, queues, Blade, Composer, tests, and your deploy pipeline. Capell adds the CMS, an editor workspace, and clean extension points, so a content site never turns into an endless series of one-off page builds.

![Capell admin dashboard](docs/images/admin-dashboard.png)

## See Capell In Practice

Capell's admin is a Filament workspace for real content operations: page trees, media, settings, theme workflows, diagnostics, and package-backed tools all sit inside the Laravel app you already deploy.

| Surface                                                                  | What it shows                                                                                                                            |
| ------------------------------------------------------------------------ | ---------------------------------------------------------------------------------------------------------------------------------------- |
| [Admin interface](docs/admin/interface.md)                               | Dashboard, Pages, Media, Settings, Theme Library, and Site Health screenshots with notes on when editors use each screen.                |
| [Create your first page](docs/getting-started/create-your-first-page.md) | A step-by-step page authoring flow with screenshots for site selection, parent pages, slug previews, content, draft saves, and settings. |
| [Music store CMS example](docs/examples/music-store-cms.md)              | A realistic content model showing how Capell maps pages, articles, events, products, artists, and navigation into a Laravel project.     |

## Why Capell

- **Stay in Laravel.** Content lives in your database as normal Eloquent models — no separate CMS product to sync with.
- **The frontend stays yours.** Render with Blade, Livewire, Inertia, Vue, static HTML, or your own stack. Capell supplies the content context; you own the output.
- **Multi-site and multi-language are first-class**, not bolted on — site domains, translated URLs, URL history, and redirects are built in.
- **Features arrive as packages, not patches.** Add fields, widgets, themes, and workflows through stable extension points without forking core.
- **Built for the long haul.** Page moves, slug redirects, draft previews, per-site editor scoping, and targeted cache invalidation are defaults, not afterthoughts.

New to the idea? [**Why Capell**](docs/getting-started/why-capell.md) compares it head-to-head with a custom Filament build, Statamic, and rolling your own — and is honest about [when _not_ to choose it](docs/getting-started/why-capell.md#when-not-to-choose-capell).

## Core Concepts

Capell's content model is a small set of nouns. Learn these and the rest of the system follows:

| Concept         | What it is                                                                      |
| --------------- | ------------------------------------------------------------------------------- |
| **Site**        | A publishing surface with its own domain(s), languages, and settings.           |
| **Language**    | A translation scope within a site; drives translated URLs and fields.           |
| **Page**        | The primary routable content entity. Pages form a tree and belong to a site.    |
| **Page URL**    | A page's per-language address, with URL history and automatic redirect records. |
| **Blueprint**   | The reusable definition behind a page, theme, site, or element type.            |
| **Layout**      | The theme-aware template structure a page renders into.                         |
| **Theme**       | The presentation layer: templates, assets, and named layout areas.              |
| **Media**       | Uploaded files (images, documents) with a swappable storage backend.            |
| **Translation** | Translatable field values, keyed per language.                                  |
| **Settings**    | Typed, schema-driven configuration surfaced on the admin Settings page.         |

The path from authoring to a public page:

```text
Site → Language → Page → Layout + widgets + assets → your frontend output
```

Definitions for every term — editor-facing and developer-facing — live in the [Glossary](docs/reference/glossary.md). For the model in depth, read [How Capell works](docs/getting-started/how-capell-works.md).

## Quick Local Demo

Capell is proprietary software distributed through private Composer access. Obtain a licence, Composer credentials, and the repository configuration for your account before running this disposable local demo:

```bash
composer create-project laravel/laravel music-store
cd music-store
composer require capell-app/installer
php artisan filament:install --panels
php artisan capell:install --demo --url=http://localhost:8000
php -S 127.0.0.1:8000 -t public public/index.php
```

Open `http://localhost:8000/admin` for the admin panel and `http://localhost:8000` for the frontend. The [Quickstart](docs/getting-started/quickstart.md) covers database setup, queue notes, screenshots, and first-run fixes.

## Start Here

Pick the path that matches what you are doing. The full route map lives at [docs/README.md](docs/README.md).

| I want to...                              | Read this                                                                                                                |
| ----------------------------------------- | ------------------------------------------------------------------------------------------------------------------------ |
| Try Capell in a fresh Laravel app         | [Quickstart](docs/getting-started/quickstart.md)                                                                         |
| Install Capell into an existing app       | [Install guide](docs/getting-started/install.md)                                                                         |
| Learn the core concepts in order          | [Capell Learn](docs/getting-started/capell-learn.md)                                                                     |
| Understand the developer architecture     | [How Capell works](docs/getting-started/how-capell-works.md)                                                             |
| Build or extend a Capell package          | [Packages](docs/packages/README.md)                                                                                      |
| Spend my first session as an editor       | [First session](docs/getting-started/first-session.md)                                                                   |
| Choose how to build a page                | [Build a page](docs/getting-started/building-pages.md)                                                                   |
| Build with Inertia, Vue, or interactivity | [Inertia runtime](docs/getting-started/inertia-runtime.md) · [Interactions](docs/getting-started/capell-interactions.md) |
| Browse every getting-started guide        | [Getting Started index](docs/getting-started/index.md)                                                                   |

## What This Repository Contains

This repo is the main **host monorepo**: the five foundation packages every Capell site is built on. First-party feature packages (themes, SEO, Publishing Studio, and more) live separately and install via Composer — browse the [package catalogue](docs/packages/catalog.md).

| Package     | Composer name            | What it owns                                                                                                 | Docs                                              |
| ----------- | ------------------------ | ------------------------------------------------------------------------------------------------------------ | ------------------------------------------------- |
| Core        | `capell-app/core`        | The content model: sites, languages, pages, URLs, layouts, themes, media, translations, settings, registries | [Overview](packages/core/docs/overview.md)        |
| Admin       | `capell-app/admin`       | The Filament editor workspace: resources, dashboards, settings, media, users, admin extension points         | [Overview](packages/admin/docs/overview.md)       |
| Frontend    | `capell-app/frontend`    | Public routing, site context, themes, assets, render hooks, response delivery, optional cache/static output  | [Overview](packages/frontend/docs/overview.md)    |
| Installer   | `capell-app/installer`   | The browser installer and installer cleanup flow                                                             | [Overview](packages/installer/docs/overview.md)   |
| Marketplace | `capell-app/marketplace` | Extension discovery, install authorization, and package acquisition                                          | [Overview](packages/marketplace/docs/overview.md) |

Each package also ships a source README:
[core](packages/core/README.md) · [admin](packages/admin/README.md) · [frontend](packages/frontend/README.md) · [installer](packages/installer/README.md) · [marketplace](packages/marketplace/README.md).

## Installing

Capell packages are not published through a public source repository or public Packagist distribution. Before either install path, obtain an appropriate licence and configure the private Composer credentials and repository details supplied by Capell. Current releases require PHP 8.4, Laravel 12.41.1+ or 13.x, and the Filament 5.7 beta release line.

### Recommended — the installer

Require the installer and run the guided flow. The installer pulls in `capell-app/core`, then `capell:install` composer-requires the admin and frontend packages you choose (default: all installable) and writes them into your app's `composer.json`. It is removable once setup finishes.

```bash
composer require capell-app/installer
php artisan capell:install
```

The full walkthrough — requirements, panel scaffolding, model patches, and headless/CI flags — is in the [install guide](docs/getting-started/install.md).

### Manual — require packages directly

Skip the installer and require exactly the packages you want. `capell-app/core` is the only hard dependency; `admin` and `frontend` are optional and each depend on core.

```bash
# Full stack, no installer
composer require capell-app/core capell-app/admin capell-app/frontend -W

# Headless / core only
composer require capell-app/core -W

# Core + admin, no public frontend
composer require capell-app/core capell-app/admin -W
```

Then run `php artisan capell:install` to apply migrations and setup; pass `--packages=` to scope it (for example `--packages=capell-app/admin`). `capell-app/capell` is the host monorepo repository, not an install target.

## How Capell Works

Capell draws deliberate package boundaries so concerns stay separate and replaceable:

- **Core** owns the reusable content model and the registries packages extend. It depends on nothing opinionated.
- **Admin** gives editors a Filament workspace over that model.
- **Frontend** connects the model to the public site through routing, site context, themes, assets, render hooks, and response delivery — then hands the final HTML to your app.
- **Packages** add fields, widgets, integrations, themes, workflows, and tools as normal Laravel packages.

Two conventions keep this clean: domain writes go through **Actions**, and structured boundary state uses **Data** objects. That keeps business rules out of Filament resources, Livewire components, controllers, and templates.

One safety rule underpins frontend output: anonymous and non-admin HTML must never leak authoring markup, model IDs, selectors, signed editor URLs, or package internals. See the [Public HTML safety contract](docs/frontend/public-html-safety.md).

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

Not sure which to reach for? Use the [Extension point chooser](docs/packages/extension-point-chooser.md), the [API reference](docs/packages/extension-point-api-reference.md), or [build one end to end](docs/packages/build-extension-end-to-end.md).

## Requirements

| Tool     | Supported versions                                                   |
| -------- | -------------------------------------------------------------------- |
| PHP      | 8.4+                                                                 |
| Laravel  | 12.41.1+ or 13.x                                                     |
| Filament | 5.7+ (currently `^5.7@beta`)                                         |
| Database | MySQL 8+, MariaDB 10.3+, SQLite, or your configured Laravel database |
| Node.js  | 20+                                                                  |
| Composer | 2.7+                                                                 |
| Runtime  | PHP-FPM or Laravel Octane (Swoole, RoadRunner, FrankenPHP)           |

See the [install guide](docs/getting-started/install.md) for required PHP extensions, permissions, and install paths.

Capell 1.x minors receive bug fixes for 12 months and security fixes for 24 months from release. The latest 1.x minor is always supported. See the [Core support policy](packages/core/README.md#requirements-and-support-policy) for the exact package requirements and backport policy.

## Contributing To This Repository

This section is for working _inside_ this monorepo. Installing Capell into your own app does not require any of it — start with the [Quickstart](docs/getting-started/quickstart.md) instead.

Common Composer scripts:

| Command                  | Purpose                                                       |
| ------------------------ | ------------------------------------------------------------- |
| `composer test`          | Run the Pest test suite                                       |
| `composer test:fast`     | Run the sharded fast Pest command                             |
| `composer lint`          | Run changed-file Pint formatting                              |
| `composer analyze`       | Run the fast PHPStan configuration                            |
| `composer preflight`     | Run PHPStan and changed-file formatting                       |
| `composer preflight:all` | Full non-mutating quality gate (checks, PHPStan, audit, Pest) |
| `composer serve`         | Build and serve the Testbench workbench at localhost          |

A Docker harness is available when you need a clean shell for agent, CI, or local verification (`docker compose up -d`, then `docker compose exec app composer test`). It is a CLI package-development harness, not an application runtime, and provides MariaDB, Redis, Mailpit, Node, and the required PHP extensions.

For local path-repository setup, branch guidance, and the contribution workflow, see [CONTRIBUTING.md](CONTRIBUTING.md) and the [Development docs](docs/development/index.md).

## License, Pricing & Support

Capell is commercial, proprietary software (`"license": "proprietary"`). Each licensed copy may run in one production environment at a time, and the license does not include updates or support unless those are part of the commercial agreement. See [LICENSE.md](LICENSE.md) for the full terms.

Licence scope and pricing are confirmed for each approved project before private package access is granted. Some companion packages require a separate entitlement; see [capell.app](https://capell.app) or contact Capell for the terms that apply.

- **Questions and discussion:** use the customer contact path or the private repository discussion area when your agreement includes repository access.
- **Documentation:** [docs.capell.app](https://docs.capell.app)
- **Security:** report privately via [SECURITY.md](SECURITY.md) — do not open a public issue for an undisclosed vulnerability.
