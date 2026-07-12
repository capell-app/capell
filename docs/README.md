# Capell Docs

Use this page as the route map. The docs are intentionally small: each page should answer a busy developer's next question without sending them through a chain of fragments.

![Capell Pages admin surface](images/capell-readme-banner.jpg)

## Start Here

| Job                                  | Read                                                                    |
| ------------------------------------ | ----------------------------------------------------------------------- |
| Try Capell quickly                   | [Quickstart](getting-started/quickstart.md)                             |
| Pick the right install path          | [Install matrix](getting-started/install-matrix.md)                     |
| Explain Capell without code          | [Why Capell](getting-started/why-capell.md)                             |
| Compare WordPress and Craft CMS      | [Capell, WordPress, and Craft CMS](getting-started/comparing-capell.md) |
| Deep-dive the developer architecture | [How Capell works](getting-started/how-capell-works.md)                 |
| See interactive page experiences     | [Capell Interactions](getting-started/capell-interactions.md)           |
| Build with Inertia                   | [Capell Inertia runtime](getting-started/inertia-runtime.md)            |
| Understand the AI-ready path         | [AI-ready Capell](getting-started/ai-ready.md)                          |
| See durable upgrade operations       | [Durable Upgrade Operations](platform/upgrade-operations.md)            |
| Learn the core concepts in order     | [Capell Learn](getting-started/capell-learn.md)                         |
| Spend a first session as an editor   | [First session](getting-started/first-session.md)                       |
 | Build a page                         | [Build a page](getting-started/building-pages.md)                       |
| Understand blueprints                | [Blueprints](getting-started/types.md)                                  |
| Install it in a real Laravel app     | [Install guide](getting-started/install.md)                             |
| Use the admin panel                  | [Admin](admin/index.md)                                                 |
| Work on public rendering             | [Frontend](frontend/index.md)                                           |
| Manage themes end to end             | [Theme Library](admin/theme-library.md)                                 |
| Build a custom theme package         | [Creating custom themes](packages/creating-custom-themes.md)            |
| Build or maintain an extension       | [Packages](packages/README.md)                                          |
| Scaffold a package                   | [Package authoring](platform/package-authoring.md)                      |
| Build an extension from scratch      | [Build an extension end to end](packages/build-extension-end-to-end.md) |
| Decide where a feature belongs       | [Host, package, or app code](development/package-boundaries.md)         |
| Work in this repo                    | [Development](development/index.md)                                     |
| Understand CI and test shards        | [CI and test shards](development/ci.md)                                 |
| Debug production behavior            | [Operations](operations/index.md)                                       |
| Plan a reversible exit               | [Export and exit plan](operations/export-and-exit.md)                   |
| See a realistic content model        | [Music store CMS example](examples/music-store-cms.md)                  |

## Build pages with the right amount of structure

Start with a normal HTML page body, move to typed blocks when content needs shape, use approved Layout Builder widgets for section composition, and keep dedicated Blade layouts for genuinely bespoke pages. The frontend stays in your Laravel application.

[Choose a page-building path](getting-started/building-pages.md)

## Featured Build Path

| Widget                    | Start                                                        | Continue                                                                                                           | Extension page                                 |
| ------------------------- | ------------------------------------------------------------ | ------------------------------------------------------------------------------------------------------------------ | ---------------------------------------------- |
| Build Inertia with Capell | [Capell Inertia runtime](getting-started/inertia-runtime.md) | [Inertia widgets](getting-started/inertia-widgets.md) and [Bookings](getting-started/inertia-bookings-showcase.md) | [Packages and extensions](packages/catalog.md) |

## Visual Tour

Use these pages when you need to see the product before reading architecture detail:

| Screen or flow         | Start with                                                          | What to look for                                                                 |
| ---------------------- | ------------------------------------------------------------------- | -------------------------------------------------------------------------------- |
| Admin workspace        | [Admin interface](admin/interface.md)                               | Dashboard, Pages, Media, Settings, Theme Library, and Site Health screenshots.   |
| First page authoring   | [Create your first page](getting-started/create-your-first-page.md) | Site and parent selection, URL preview, content editor, draft actions, settings. |
| Theme management       | [Theme Library](admin/theme-library.md)                             | Installed themes, available themes, diagnostics, customize, preview, and apply.  |
| Operations diagnostics | [Site Health](operations/site-health.md)                            | Cache status, public-output safety, static generation, optimizer, server checks. |
| Real content model     | [Music store CMS example](examples/music-store-cms.md)              | How pages, articles, events, products, artists, and navigation fit together.     |

## Main Docs

| Page                                               | Covers                                                                                                                                 |
| -------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------- |
| [Getting Started](getting-started/index.md)        | Evaluation, install paths, first authoring session, core concepts, and interactive build paths.                                        |
| [Admin](admin/index.md)                            | Filament resources, settings, users, media, recovery shell, dashboard Filament widgets, admin extension points.                        |
| [Frontend](frontend/index.md)                      | Site/page resolution, public HTML safety, media output, render hooks, Tailwind assets, server rules, frontend tests.                   |
| [Packages](packages/README.md)                     | Package shape, manifests, service providers, extension points, Actions/Data/settings, admin/frontend contributions, migrations, tests. |
| [Performance & Caching](performance/README.md)     | Page cache, fragment cache, model URL cache, ETags, critical assets, lazy hydration.                                                   |
| [Package authoring](platform/package-authoring.md) | Package authoring surfaces and [durable upgrade operations](platform/upgrade-operations.md) for Capell platform capabilities.          |
| [Operations](operations/index.md)                  | Site Health, Lockdown, upgrades, Marketplace connection, install/admin/frontend troubleshooting.                                       |
| [Development](development/index.md)                | Local repo shape, commands, configuration, seeders, diagnostics, settings migrations.                                                  |
| [Reference](reference/index.md)                    | Glossary, relationship maps, credits, and package boundary references.                                                                 |

## Common Decisions

| Question                                                     | Read                                                                                                                                                          |
| ------------------------------------------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Which install path should I use?                             | [Install matrix](getting-started/install-matrix.md)                                                                                                           |
| How should I build this page?                                | [Build a page](getting-started/building-pages.md)                                                                                                             |
| Does this belong in the host repo, a package, or an app?     | [Host, package, or app code](development/package-boundaries.md)                                                                                               |
| Which extension point should a package use?                  | [Extension point chooser](packages/extension-point-chooser.md)                                                                                                |
| How should I scaffold a package?                             | [Package authoring](platform/package-authoring.md)                                                                                                            |
| How should packages integrate with Installer or Marketplace? | [Installer extension contracts](packages/installer-extension-contracts.md) and [Marketplace extension contracts](packages/marketplace-extension-contracts.md) |
| What exact contract/tag/test should I use?                   | [Extension point API reference](packages/extension-point-api-reference.md)                                                                                    |
| Why is my package contribution missing?                      | [Extension troubleshooting](packages/extension-troubleshooting.md)                                                                                            |
| What can public HTML expose?                                 | [Public HTML safety contract](frontend/public-html-safety.md)                                                                                                 |
| How do themes reach the frontend?                            | [Frontend themes](frontend/themes.md)                                                                                                                         |
| How do I create a theme package?                             | [Creating custom themes](packages/creating-custom-themes.md)                                                                                                  |
| How do lazy widgets and fragments work?                      | [Capell Interactions](getting-started/capell-interactions.md)                                                                                                 |
| Which patterns are forbidden?                                | [Do not do this](development/do-not-do-this.md)                                                                                                               |
| Where should a new doc go?                                   | [Docs ownership rules](development/docs-ownership.md)                                                                                                         |

## URL Preservation

Treat published URLs as durable. When replacing, moving, or rebuilding an old page, keep the new page clean and preserve the old address with a Page URL whose type is **Redirect**. The redirect record owns the legacy source path and points it at the current page URL, so visitors, bookmarks, inbound links, and search engines still reach the right content without keeping a duplicate page around.

Capell also creates automatic redirect Page URLs when a published page URL changes because a slug or parent path changed. Add a manual redirect when you are creating a new page to replace an existing URL, consolidating old content, or importing legacy routes from another CMS.

For the detailed model, read [Page management: URL history and redirects](../packages/core/docs/page-management.md#url-history-and-redirects).

## Host Packages

| Package     | Composer name            | Package doc                                                      |
| ----------- | ------------------------ | ---------------------------------------------------------------- |
| Core        | `capell-app/core`        | [Core overview](../packages/core/docs/overview.md)               |
| Admin       | `capell-app/admin`       | [Admin overview](../packages/admin/docs/overview.md)             |
| Frontend    | `capell-app/frontend`    | [Frontend overview](../packages/frontend/docs/overview.md)       |
| Installer   | `capell-app/installer`   | [Installer overview](../packages/installer/docs/overview.md)     |
| Marketplace | `capell-app/marketplace` | [Marketplace overview](../packages/marketplace/docs/overview.md) |

First-party add-ons are documented by their generated extension pages and package-owned READMEs. Use [Packages and extensions](packages/catalog.md) for host package boundaries and authoring entry points.

## Rules For New Docs

- Update an existing page before adding a new file.
- Link every new doc from this page, a section index, a package overview, or another doc.
- Put historical plans outside the public docs tree.
- Do not keep "moved" stubs unless a published URL needs a temporary redirect.
- Keep extension-point examples beside the reader task that needs them.
- Do not document optional packages as built-in host behavior.
- Public frontend docs must preserve the safety rule: anonymous HTML must not expose authoring controls, model IDs, selectors, signed editor URLs, or package internals.
