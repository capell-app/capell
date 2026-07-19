# Capell CMS

![Capell CMS page administration in a Laravel application](docs/images/capell-readme-banner.jpg)

[![Latest Tag](https://img.shields.io/github/v/tag/capell-app/capell?style=flat-square&label=release)](https://github.com/capell-app/capell/tags)
[![Test Matrix](https://img.shields.io/github/actions/workflow/status/capell-app/capell/test-full.yml?branch=main&style=flat-square&label=test%20matrix&logo=githubactions&logoColor=white)](https://github.com/capell-app/capell/actions/workflows/test-full.yml)
[![Quality Gates](https://img.shields.io/github/actions/workflow/status/capell-app/capell/code-quality-and-styling.yml?branch=main&style=flat-square&label=quality%20gates&logo=githubactions&logoColor=white)](https://github.com/capell-app/capell/actions/workflows/code-quality-and-styling.yml)
[![Coverage](https://img.shields.io/codecov/c/github/capell-app/capell?style=flat-square&logo=codecov&logoColor=white)](https://app.codecov.io/gh/capell-app/capell)
<br>
[![PHPStan Level 8](https://img.shields.io/badge/PHPStan-level%208-777BB4?style=flat-square&logo=php&logoColor=white)](https://github.com/capell-app/capell/actions/workflows/code-quality-and-styling.yml)
[![Parameters Typed](https://img.shields.io/badge/parameters%20typed-98.9%25-2F855A?style=flat-square)](https://github.com/capell-app/capell/actions/workflows/code-quality-and-styling.yml)
[![Dependencies Audited](https://img.shields.io/badge/dependencies-audited-885630?style=flat-square&logo=composer&logoColor=white)](https://github.com/capell-app/capell/actions/workflows/code-quality-and-styling.yml)
[![PHP](https://img.shields.io/badge/PHP-8.4-777BB4?style=flat-square&logo=php&logoColor=white)](https://www.php.net/releases/8.4/en.php)
[![Laravel](https://img.shields.io/badge/Laravel-12.41%2B%20%7C%2013-FF2D20?style=flat-square&logo=laravel&logoColor=white)](#requirements)
[![Documentation](https://img.shields.io/badge/docs-docs.capell.app-blue?style=flat-square)](https://docs.capell.app)

**Capell is a Laravel CMS built on Filament for teams whose application needs more than a few editable pages.** Editors get a structured page workspace. Developers keep the public frontend, deployment, and application architecture inside Laravel.

Choose Capell when repeated page types, layouts, URLs, publishing rules, and editor workflows should be defined once and improved in one place. Choose WordPress, Statamic, Craft, or a dedicated headless CMS when its ecosystem, file model, hosted workflow, or delivery API is the better fit.

[Try the guided demo](https://capell.app/demo) · [Follow the verified quickstart](docs/getting-started/quickstart.md) · [Build a page](docs/getting-started/building-pages.md) · [Read the fit guide](docs/getting-started/why-capell.md)

## Why teams choose Capell

| Outcome                          | What ships                                                                                                 |
| -------------------------------- | ---------------------------------------------------------------------------------------------------------- |
| Recover page content confidently | Append-only page revision history, readable diffs, rollback preview, validated roll back, and roll forward |
| Upgrade visibly                  | Dry-run planning, recorded upgrade steps, diagnostics, and step rollback where the step declares it safe   |
| Keep the frontend yours          | Laravel routing and render integration for Blade, Livewire, Inertia, Vue, or a custom stack                |
| Extend without patching Core     | Composer packages, manifests, registries, health checks, install and uninstall lifecycles                  |

![A readable Capell activity diff showing old and new page values](docs/images/generated/admin/admin-activity-log-details.png)

## What Capell is and isn't

Capell is not a hosted CMS and does not ship a public content-delivery API. Your pages render through your own Laravel application, using its routing and frontend stack.

- Page revision history recovers page content. It is not a database or media backup; configure those in the host application.
- Upgrade rollback covers recorded upgrade steps that declare a safe rollback. Infrastructure and database rollback belong to the deployed application.
- The Admin history surface records page changes and supports page-scoped recovery. Broader editorial workspaces, assignments, approvals, and release workflows come from the optional Publishing Studio package, which sits outside the foundation.
- Optional packages vary in maturity. Review a package's distribution channel, supported versions, data access, and removal path before adopting it.

## See the product before installing

The [guided demo](https://capell.app/demo) explains its reset and read-only boundaries before sending you to the shared environment. For a source-level evaluation, these current captures show the foundation workflow:

| Organise and publish pages                                                                | Edit structured page content                                                                                           |
| ----------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------- |
| ![Capell Pages list with publish states and page types](docs/images/admin-pages-list.png) | ![Capell page editor with content, publishing, and page context](docs/images/generated/admin/admin-page-edit-form.png) |

Continue with [Create your first page](docs/getting-started/create-your-first-page.md) for the full field-by-field journey.

## Verified quickstart

Start from a fresh supported Laravel application. `capell-app/installer` is the entry point: its guided command adds the selected foundation packages and runs each package lifecycle. It then creates the first site and administrator, generates frontend assets, synchronises permissions, and finishes only when its required health summary passes.

```bash
composer create-project laravel/laravel capell-site
cd capell-site

# Configure APP_URL and a supported database in .env first.
composer require capell-app/installer
php artisan capell:install --demo
```

The guided command asks for the site URL and first administrator. A successful run ends with `All checks passed` followed by `Installation complete`. If a required step fails, the command exits non-zero and does not print the success message.

The `capell-app/capell` package installs all five foundation packages at the same version: Core, Admin, Frontend, Installer, and Marketplace. Requiring it does not replace the guided installer workflow; `php artisan capell:install` is still how Capell gets set up.

Then run the application using your normal Laravel development workflow and open:

- `/admin` to sign in with the administrator created during installation;
- `/` to inspect the seeded public page;
- **Pages** in Admin to make and publish the first change.

Do not run `filament:install --panels` before requiring Capell: the installer brings in and configures the selected Admin package. See the [Quickstart](docs/getting-started/quickstart.md) for SQLite and queue setup, expected prompts, health checks, and first-run recovery.

### Capell Membership install

An active Capell Membership organisation can request a short-lived Composer command from its Capell account. Run the generated commands in the Laravel application, then use the same Installer flow:

```bash
composer config repositories.capell composer https://capell.app/composer
composer config bearer.capell.app <short-lived-token>
composer require capell-app/capell
php artisan capell:install
```

Marketplace then authorises the Membership catalogue for the connected organisation. The token is scoped, expires within 30 minutes, and is redacted from account serialisation. Do not paste it into tickets, logs, source control, or shared shell history; request a new command when it expires.

## Theme it

Installed themes use one Admin path: **Theme Library → Customize → Preview → Apply**. Preview a change against real content before making it active, and keep theme presentation in the Laravel application rather than in page records.

Read [Theme Library](docs/admin/theme-library.md) to operate installed themes or [Creating custom themes](docs/packages/creating-custom-themes.md) to own the Blade, assets, settings, and compatibility contract yourself. Marketplace themes are only installable when their listing states a released distribution path and compatible Capell line.

## Extend it

The public foundation is split into five packages. Foundation packages install from public Packagist repositories. Paid marketplace packages require authenticated Composer access and an active entitlement.

| Package     | Composer name            | Responsibility                                                                                                                             |
| ----------- | ------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------ |
| Core        | `capell-app/core`        | Sites, languages, pages, URLs, layouts, themes, media, translations, settings, revision history, upgrade foundations, and registries       |
| Admin       | `capell-app/admin`       | Filament resources, editor workflows, page recovery UI, settings, users, diagnostics, and admin extension points                           |
| Frontend    | `capell-app/frontend`    | Public routing, site context, themes, [typed resources](packages/frontend/docs/frontend-resources.md), render hooks, and response delivery |
| Installer   | `capell-app/installer`   | Guided browser and CLI installation, health review, and installer cleanup                                                                  |
| Marketplace | `capell-app/marketplace` | Extension discovery, install authorisation, and package acquisition contracts                                                              |

Optional features arrive as Laravel packages. The [package catalogue](docs/packages/catalog.md) distinguishes foundation contracts from optional package documentation, and states each package's distribution channel and maturity.

Package authors should start with the [extension-point chooser](docs/packages/extension-point-chooser.md) and [package authoring guide](docs/packages/README.md). Capell packages extend registries and lifecycle contracts instead of patching host classes.

## Operate it

Preview upgrades before applying them:

```bash
php artisan capell:upgrade --dry-run
php artisan capell:upgrade
php artisan capell:doctor
```

Rollback is available only for recorded upgrade steps that implement a safe rollback:

```bash
php artisan capell:rollback --step=<step-id> --dry-run
php artisan capell:rollback --step=<step-id> --force
```

Backups are a separate operational contract. Configure database and media backups, offsite retention, monitoring, and scratch restores in the host application; then use Capell's backup health and restore tooling to verify that configuration.

Read these before production:

- [Upgrading and rollback](docs/operations/upgrading.md)
- [Database and media backups](docs/operations/backups.md)
- [Site health](docs/operations/site-health.md)
- [Lockdown and break-glass access](docs/operations/lockdown.md)
- [Export and exit](docs/operations/export-and-exit.md)

## Requirements

| Tool     | Supported versions                                                  |
| -------- | ------------------------------------------------------------------- |
| PHP      | 8.4+                                                                |
| Laravel  | 12.41.1+ in the 12.x line or Laravel 13.x                           |
| Filament | 5.6.8+ (`~5.6.8`)                                                   |
| Database | MySQL 8+, MariaDB 10.3+, SQLite, or the configured Laravel database |
| Node.js  | 20+                                                                 |
| Composer | 2.7+                                                                |
| Runtime  | PHP-FPM or Laravel Octane (Swoole, RoadRunner, or FrankenPHP)       |

Required PHP extensions and writable paths are listed in the [Install guide](docs/getting-started/install.md). The shipped product line is 1.x; use the latest compatible tag rather than a branch name in customer applications.

For the shipped 1.x line, each minor receives security fixes for 24 months from its release date, and the latest 1.x minor is always supported. See the [security policy](SECURITY.md) and [Core support policy](packages/core/README.md#requirements-and-support-policy) for the exact contract.

## Pricing, support, security, and licence

- [Current pricing and commercial availability](https://capell.app/pricing)
- [Support boundaries](https://capell.app/support)
- [Security policy](SECURITY.md)
- [Capell licence](LICENSE.md)

Capell is commercial software (`"license": "proprietary"`) with public foundation source and Composer distribution. Public visibility does not change the Capell licence. See [LICENSE.md](LICENSE.md) for the full terms.

## Contributing to this repository

This source monorepo contains the five foundation packages and their release-contract tests. It is not the package name installed into customer applications.

```bash
composer test
composer lint
composer analyze
composer preflight
```

See [CONTRIBUTING.md](CONTRIBUTING.md) for repository setup, Docker harness use, path repositories, release checks, and pull-request expectations.
