# Capell Docs

Use this page to find the shortest route to your next task.

![Capell Pages admin surface](images/capell-readme-banner.jpg)

## Choose Your Path

| I want to...                  | Start with                                                              | Then read                                                                                                                                        |
| ----------------------------- | ----------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------ |
| Evaluate or install Capell    | [Why Capell](getting-started/why-capell.md)                             | [Compare Capell with WordPress and Craft](getting-started/comparing-capell.md), then choose an [install path](getting-started/install-matrix.md) |
| Build and edit a site         | [Your first session](getting-started/first-session.md)                  | [Create your first page](getting-started/create-your-first-page.md), then [choose a page-building path](getting-started/building-pages.md)       |
| Build a page                  | [Build a page](getting-started/building-pages.md)                       | Choose the right authoring structure, from HTML through dedicated Blade layouts.                                                                 |
| Build an extension            | [Build an extension end to end](packages/build-extension-end-to-end.md) | [Host, package, or app code](development/package-boundaries.md), then the [extension point chooser](packages/extension-point-chooser.md)         |
| Operate a production site     | [Operations](operations/index.md)                                       | [Back up the site](operations/backups.md), then follow the [upgrade runbook](operations/upgrading.md)                                            |
| Maintain the Capell host repo | [Development](development/index.md)                                     | [Local development](development/local-development.md), then [CI and test shards](development/ci.md)                                              |

## Visual Tour

Use these pages to see a workflow before reading its implementation details.

| Screen or flow         | Start with                                                          | What to look for                                                                 |
| ---------------------- | ------------------------------------------------------------------- | -------------------------------------------------------------------------------- |
| Admin workspace        | [Admin interface](admin/interface.md)                               | Dashboard, Pages, Media, Settings, Theme Library, and Site Health.               |
| First page authoring   | [Create your first page](getting-started/create-your-first-page.md) | Site and parent selection, URL preview, content editor, draft actions, settings. |
| Theme management       | [Theme Library](admin/theme-library.md)                             | Installed and available themes, diagnostics, customization, preview, and apply.  |
| Operations diagnostics | [Site Health](operations/site-health.md)                            | Cache status, public-output safety, static generation, optimizer, server checks. |
| Real content model     | [Music store CMS example](examples/music-store-cms.md)              | Pages, articles, events, products, artists, and navigation working together.     |

## Build pages with the right amount of structure

[Choose a page-building path](getting-started/building-pages.md) based on what editors and the frontend need: a normal HTML page body, typed blocks, approved Layout Builder widgets, or dedicated Blade layouts. The frontend stays in your Laravel application.

## Documentation Sections

| Section                                            | Covers                                                                                                              |
| -------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------- |
| [Getting Started](getting-started/index.md)        | Evaluation, installation, first authoring tasks, core concepts, and interactive build paths.                        |
| [Admin](admin/index.md)                            | Content, media, users, settings, themes, the dashboard, and admin extension points.                                 |
| [Frontend](frontend/index.md)                      | Site and page resolution, public HTML safety, themes, media, render hooks, assets, and frontend tests.              |
| [Packages](packages/README.md)                     | Package ownership, manifests, providers, extension points, admin and frontend contributions, migrations, and tests. |
| [Performance](performance/README.md)               | Page and fragment caches, model URL caches, ETags, critical assets, and lazy hydration.                             |
| [Package authoring](platform/package-authoring.md) | Platform authoring surfaces and durable package operations.                                                         |
| [Operations](operations/index.md)                  | Site Health, backups, Lockdown, upgrades, Marketplace connection, and production troubleshooting.                   |
| [Development](development/index.md)                | Host repo setup, commands, configuration, seeders, diagnostics, and CI.                                             |
| [Reference](reference/index.md)                    | Glossary, relationship maps, architecture diagrams, credits, and package boundaries.                                |

## High-Risk Decisions

| I need to...                                      | Read                                                                                                                         |
| ------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------- |
| Preserve a published URL after moving content     | [Page management: URL history and redirects](../packages/core/docs/page-management.md#url-history-and-redirects)             |
| Decide whether code belongs in the host or add-on | [Host, package, or app code](development/package-boundaries.md)                                                              |
| Change anonymous frontend output                  | [Public HTML safety contract](frontend/public-html-safety.md)                                                                |
| Choose or add an extension point                  | [Extension point chooser](packages/extension-point-chooser.md) and [unsafe patterns to avoid](development/do-not-do-this.md) |
| Change production package or database state       | [Backups and restore](operations/backups.md) and [upgrades](operations/upgrading.md)                                         |
| Plan a reversible move away from Capell           | [Export and exit plan](operations/export-and-exit.md)                                                                        |
| How should I build this page?                     | [Build a page](getting-started/building-pages.md)                                                                            |

Published URLs are durable. Capell creates redirect Page URLs when a published page URL changes because its slug or parent path changed. Add a manual redirect when replacing content, consolidating pages, or importing legacy routes.

## Host Packages

The host repo owns these five packages. Optional add-on behavior belongs to the package that provides it.

| Package     | Composer name            | Package documentation                                            |
| ----------- | ------------------------ | ---------------------------------------------------------------- |
| Core        | `capell-app/core`        | [Core overview](../packages/core/docs/overview.md)               |
| Admin       | `capell-app/admin`       | [Admin overview](../packages/admin/docs/overview.md)             |
| Frontend    | `capell-app/frontend`    | [Frontend overview](../packages/frontend/docs/overview.md)       |
| Installer   | `capell-app/installer`   | [Installer overview](../packages/installer/docs/overview.md)     |
| Marketplace | `capell-app/marketplace` | [Marketplace overview](../packages/marketplace/docs/overview.md) |

Use [Packages and extensions](packages/catalog.md) for add-on boundaries and authoring entry points.

## Documentation Ownership

Update an existing page before adding a new one, and link every new page from the narrowest relevant index. Keep optional-package behavior with its owning package and public frontend safety rules beside rendering guidance.

Use [Docs ownership rules](development/docs-ownership.md) to choose the right location and avoid duplicate or orphaned pages.
