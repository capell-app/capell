# Capell CMS

![Fine-line engraved blueprint on deep navy showing Capell's foundation feeding two branching structures, with the Capell wordmark on the left](docs/images/capell-readme-hero.jpg)

[![Latest Tag](https://img.shields.io/github/v/tag/capell-app/capell?style=flat-square&label=release)](https://github.com/capell-app/capell/tags)
[![Test Matrix](https://img.shields.io/github/actions/workflow/status/capell-app/capell/test-full.yml?branch=main&style=flat-square&label=test%20matrix&logo=githubactions&logoColor=white)](https://github.com/capell-app/capell/actions/workflows/test-full.yml)
[![Coverage](https://img.shields.io/codecov/c/github/capell-app/capell?style=flat-square&logo=codecov&logoColor=white)](https://app.codecov.io/gh/capell-app/capell)
[![PHP](https://img.shields.io/badge/PHP-8.4-777BB4?style=flat-square&logo=php&logoColor=white)](https://www.php.net/releases/8.4/en.php)
[![Laravel](https://img.shields.io/badge/Laravel-13-FF2D20?style=flat-square&logo=laravel&logoColor=white)](#requirements)

**Every change can be undone.**

Capell is an open-source CMS for Laravel, built on Filament. It starts where custom builds end: page patterns, previews, revision history, rollback and safe upgrades are already built and ready to extend.

Install it into an existing Laravel application with two commands:

```bash
composer require capell-app/installer
php artisan capell:install
```

[Open the live demo](https://capell.app/demo) · [See all features](https://capell.app/features) · [Follow the verified quickstart](docs/getting-started/quickstart.md) · [Build a page](docs/getting-started/building-pages.md) · [Read the fit guide](docs/getting-started/why-capell.md) · [Read the docs](https://docs.capell.app)

Capell is not a hosted CMS and does not ship a public content-delivery API. Your pages render inside your Laravel application through Blade, Livewire, Inertia, Vue, or your own stack.

## See it running a real site

These captures show Capell administering [capell.app](https://capell.app) — the Capell website is itself a Capell site, so what you see below is the product managing real production pages.

![Capell Pages list showing real published pages with publish states, layouts, and SEO overview](docs/images/capell-app-pages-list.png)

| Editing a page                                                                                                           | What visitors see                                                                                             |
| ------------------------------------------------------------------------------------------------------------------------ | ------------------------------------------------------------------------------------------------------------- |
| ![Capell page editor with real page content, publishing state, and AI assistant](docs/images/capell-app-page-editor.png) | ![The capell.app public homepage rendered by the Capell frontend](docs/images/capell-app-public-homepage.jpg) |

The [guided demo](https://capell.app/demo) explains its reset and read-only boundaries before sending you to the shared environment. Continue with [Create your first page](docs/getting-started/create-your-first-page.md) for the full field-by-field journey.

## Why we built Capell

The real CMS test starts after the first launch. Someone edits the homepage at 4:55pm on a Friday. Someone asks "who approved this?". An upgrade lands and nobody is sure what it changed. A year later the project is handed over and the CMS decisions are folklore. Capell exists for those moments, so the answers are product features rather than heroics:

- **A recoverable page beats a perfect handover document.** Page changes have append-only history, readable diffs, rollback preview, validated roll back, and roll forward. Standard recovery is page-scoped; it is not a database or media backup.
- **Upgrades are a command, not a project.** `capell:install`, `capell:upgrade --dry-run`, and `capell:doctor` give every team the same repeatable operating path, with recorded steps and rollback where a step declares it safe.
- **The frontend stays yours.** Editors compose approved structure in Filament; anonymous visitors receive clean HTML with no authoring markers, editor controls, or admin leakage.
- **Leaving must be possible.** Export and exit are documented contracts, not an afterthought.

## The ecosystem is the point

Capell keeps its core lean and grows through packages, so the CMS can expand with the application instead of arriving as a monolith. The free core stands on its own; the paid packages add the power tools. These are the ten headline features from [capell.app/features](https://capell.app/features):

| Feature                         | What it gives you                                                                                                                                    |
| ------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------- |
| Extendable core                 | A lean, fast core you grow through verified packages, each with a production footprint you can inspect before it installs                            |
| Safe upgrades and page recovery | Every change is reversible: compare revisions and roll a page back or forward with its audit trail intact                                            |
| Multi-site and multilingual     | Run many sites and languages from one Laravel app, with each site's content kept distinct                                                            |
| Quality, tested code            | Built and tested in the open: every quality claim traces to a live CI result you can check yourself                                                  |
| AI-ready                        | Built to be driven by AI and served to AI through governed extension points                                                                          |
| AI Command Centre               | Say the outcome in plain language, see the exact change previewed, and approve it before anything happens                                            |
| Agent Delivery                  | Optional marketplace package for publishing clean, RAG-ready JSON to answer engines; Foundation supplies the structured, permissioned source content |
| SEO Suite                       | Live scoring, schema, redirects, and Search Console, all inside the admin                                                                            |
| Site search                     | Relevance-tuned results with synonyms and typo tolerance, plus a report of what visitors search for and cannot find                                  |
| Performance pipeline            | Pages are cached and each ships route-specific critical CSS generated from your real stylesheet                                                      |

[![AI Ready](https://img.shields.io/badge/AI-ready-6D28D9?style=flat-square)](docs/getting-started/ai-ready.md)
Content stays structured and permissioned, so it is [ready for search engines and AI](docs/getting-started/ai-ready.md) without scraping or admin leakage.

Optional capabilities arrive as Laravel packages you add when the work needs them. Before installing one, verify its distribution channel, maturity, supported Capell/Laravel/Filament versions, data access, migrations, support terms, and removal path — the [package catalogue](docs/packages/catalog.md) distinguishes foundation contracts from optional package documentation, and the live [extensions directory](https://capell.app/extensions) is the authority for what is currently installable.

Package authors should start with the [extension-point chooser](docs/packages/extension-point-chooser.md) and [package authoring guide](docs/packages/README.md). Capell packages extend registries and lifecycle contracts instead of patching host classes.

## Built as a standard Laravel package

There is no separate runtime and no parallel framework to learn. Capell is plain Laravel — Eloquent models, Actions, events, queues, and Filament resources — developed in this open monorepo and published to Packagist as ordinary Composer packages.

Page schemas are defined by **blueprints**: define a page type's fields once, then extend the schema per project instead of writing another bespoke resource. Underneath is a slim, strictly typed and well-tested core. Filament editing and public rendering stay completely separate, while new capabilities plug in through normal Laravel packages instead of core patches.

Capell Foundation is MIT-licensed and installs from public Packagist repositories without a Capell account. Paid marketplace packages remain commercially licensed and use separate commercial terms and entitlement-scoped Composer access; the [licensing page](https://capell.app/licensing) explains the split.

The public foundation is five packages:

| Package     | Composer name            | Responsibility                                                                                                                             |
| ----------- | ------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------ |
| Core        | `capell-app/core`        | Sites, languages, pages, URLs, layouts, themes, media, translations, settings, revision history, upgrade foundations, and registries       |
| Admin       | `capell-app/admin`       | Filament resources, editor workflows, page recovery UI, settings, users, diagnostics, and admin extension points                           |
| Frontend    | `capell-app/frontend`    | Public routing, site context, themes, [typed resources](packages/frontend/docs/frontend-resources.md), render hooks, and response delivery |
| Installer   | `capell-app/installer`   | Guided browser and CLI installation, health review, and installer cleanup                                                                  |
| Marketplace | `capell-app/marketplace` | Extension discovery, install authorisation, and package acquisition contracts                                                              |

## Verified quickstart

Start from a fresh supported Laravel application. The installer adds the selected foundation packages, runs each package lifecycle, creates the first site and administrator, generates frontend assets, synchronises permissions, and finishes only when its required health summary passes.

```bash
composer create-project laravel/laravel capell-site
cd capell-site

# Configure APP_URL and a supported database in .env first.
composer require capell-app/installer
php artisan capell:install --demo
```

The guided command asks for the site URL and first administrator. A successful run ends with `All checks passed` followed by `Installation complete`. If a required step fails, the command exits non-zero and does not print the success message.

The canonical installation entry point for an existing Laravel application is `capell-app/installer`. The `capell-app/capell` package is the supported, version-aligned foundation aggregate for the Core, Admin, Frontend, Installer, and Marketplace code line; it does not replace the guided Installer workflow.

Then run the application using your normal Laravel development workflow and open:

- `/admin` to sign in with the administrator created during installation;
- `/` to inspect the seeded public page;
- **Pages** in Admin to make and publish the first change.

Do not run `filament:install --panels` before requiring Capell: the installer brings in and configures the selected Admin package. See the [Quickstart](docs/getting-started/quickstart.md) for SQLite and queue setup, expected prompts, health checks, and first-run recovery.

### Capell Membership install

An active Capell Membership organisation can request a short-lived private Composer command from its Capell account. Run the generated commands in the Laravel application, then use the same Installer flow:

```bash
composer config repositories.capell composer https://capell.app/composer
composer config bearer.capell.app <short-lived-token>
composer require capell-app/capell
php artisan capell:install
```

`capell-app/capell` is the root aggregate for the aligned Core, Admin, Frontend, Installer, and Marketplace code line. Marketplace then authorises the Membership catalogue for the connected organisation. The token is scoped, expires within 30 minutes, and is redacted from account serialization. Do not paste it into tickets, logs, source control, or shared shell history; request a new command when it expires.

## Theme it

Installed themes use one Admin path: **Theme Library → Customize → Preview → Apply**. Preview a change against real content before making it active, and keep theme presentation in the Laravel application rather than in page records.

Read [Theme Library](docs/admin/theme-library.md) to operate installed themes or [Creating custom themes](docs/packages/creating-custom-themes.md) to own the Blade, assets, settings, and compatibility contract yourself. Marketplace themes are only installable when their listing states a released distribution path and compatible Capell line; the [theme gallery](https://capell.app/extensions/themes) shows what each ships on the public web.

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

Backups are a separate operational contract. Configure database and media backups, offsite retention, monitoring, and scratch restores in the host application; then use Capell's backup health and restore tooling to verify that configuration. Page history does not recover a lost database or media store.

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
| Laravel  | 13.x                                                                |
| Filament | 5.6.8+ (`~5.6.8`)                                                   |
| Database | MySQL 8+, MariaDB 10.3+, SQLite, or the configured Laravel database |
| Node.js  | 20+                                                                 |
| Composer | 2.7+                                                                |
| Runtime  | PHP-FPM or Laravel Octane (Swoole, RoadRunner, or FrankenPHP)       |

Required PHP extensions and writable paths are listed in the [Install guide](docs/getting-started/install.md). The shipped product line is 1.x; use the latest compatible tag rather than a branch name in customer applications.

For the shipped 1.x line, each minor receives security fixes for 24 months from its release date, and the latest 1.x minor is always supported. See the [security policy](SECURITY.md) and [Core support policy](packages/core/README.md#requirements-and-support-policy) for the exact contract.

## Pricing, support, security, and licence

- [Current pricing and commercial availability](https://capell.app/pricing)
- [Licensing and package access](https://capell.app/licensing)
- [Support boundaries](https://capell.app/support)
- [Security policy](SECURITY.md)
- [MIT licence](LICENSE.md)

Commercial facts live on the Capell website so this README does not preserve stale prices, package counts, discounts, or checkout claims. Capell Foundation is MIT-licensed. Paid marketplace packages remain commercially licensed under their separate terms; public visibility never grants protected package access. See [LICENSE.md](LICENSE.md) for the Foundation terms.

## Contributing to this repository

[![Quality Gates](https://img.shields.io/github/actions/workflow/status/capell-app/capell/code-quality-and-styling.yml?branch=main&style=flat-square&label=quality%20gates&logo=githubactions&logoColor=white)](https://github.com/capell-app/capell/actions/workflows/code-quality-and-styling.yml)
[![PHPStan Level 8](https://img.shields.io/badge/PHPStan-level%208-777BB4?style=flat-square&logo=php&logoColor=white)](https://github.com/capell-app/capell/actions/workflows/code-quality-and-styling.yml)
[![Parameters Typed](https://img.shields.io/badge/parameters%20typed-99.2%25-2F855A?style=flat-square)](https://github.com/capell-app/capell/actions/workflows/code-quality-and-styling.yml)
[![Dependencies Audited](https://img.shields.io/badge/dependencies-audited-885630?style=flat-square&logo=composer&logoColor=white)](https://github.com/capell-app/capell/actions/workflows/code-quality-and-styling.yml)

This source monorepo contains the five foundation packages and their release-contract tests. It is not the package name installed into customer applications.

```bash
composer test
composer lint
composer analyze
composer preflight
```

See [CONTRIBUTING.md](CONTRIBUTING.md) for repository setup, Docker harness use, path repositories, release checks, and pull-request expectations.
