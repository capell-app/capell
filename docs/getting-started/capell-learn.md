# Learn Capell

One guided path from "never heard of Capell" to a published page you understand. Eight stages, about 95 minutes including the first Composer install, and nothing marked optional is required. Each stage page carries a banner that brings you back here.

Already installed? Start at stage 3. Only evaluating? Stages 1 and 5 are enough to decide.

## The path

| Stage | Goal | Read (required) | Time | Dive deeper (optional) |
| --- | --- | --- | --- | --- |
| 1 | Decide whether Capell fits | [Why Capell](why-capell.md): the opening and "The decision in one minute" | 5 min | [Compare with WordPress and Craft](comparing-capell.md), the [guided demo](https://capell.app/demo), [Export and exit](../operations/export-and-exit.md) |
| 2 | Install a disposable site with Admin and the public page both styled | [Quickstart](quickstart.md): "Before you start" and steps 1 to 3 | 20 min | [Install matrix](install-matrix.md), [existing applications](install.md#existing-laravel-applications), [containers](../development/container-development.md), [Troubleshooting](../operations/troubleshooting.md) |
| 3 | Find your way around Admin: site switcher, sidebar, Clear Cache, Settings | [Your first session](first-session.md): "Log in", "The dashboard" and "Configure your site settings" | 10 min | [Admin interface](../admin/interface.md), [Users and roles](../admin/users-and-roles.md) |
| 4 | Publish your own page and see it as a signed-out visitor | [Create your first page](create-your-first-page.md): the required steps listed at the top of that page | 20 min | The sections marked optional on the same page, [Media management](../admin/media-management.md) |
| 5 | Explain Site, Page, Page URL, Blueprint, Layout and Theme using the page you just made | [How Capell works](how-capell-works.md#the-core-model): "Capell in five minutes" and "The Core Model" | 10 min | [Blueprints](types.md), [Core relationship map](../reference/core-erd.md), [URL history and redirects](../../packages/core/docs/page-management.md#url-history-and-redirects), [Music store example](../examples/music-store-cms.md) |
| 6 | Choose how pages are built, and change how the site looks | [Build a page](building-pages.md) up to "A practical default", then [Theme Library](../admin/theme-library.md) | 15 min | "Custom Blade rendering" on the same page, [Layout Builder](../packages/layout-builder.md), [Content Sections](../packages/content-sections.md), [Creating custom themes](../packages/creating-custom-themes.md) |
| 7 | Change a live page safely: compare versions, preview a rollback, check site health | [Quickstart](quickstart.md#4-publish-your-first-page-and-inspect-history) steps 4 and 5, then [Previewing and applying rollback](../development/page-event-history.md#previewing-and-applying-rollback) | 10 min | [Backups](../operations/backups.md), [Site Health](../operations/site-health.md), [Upgrading](../operations/upgrading.md) |
| 8 | Leave the path on purpose | "Choose your next track" below | 5 min | |

## What you do not need yet

Everything below is optional and appears in the docs because someone's site eventually needs it. Skip it until the note on the right applies to you.

| Skip for now | Add it when |
| --- | --- |
| Layout Builder, Content Sections, Block Library | editors need reusable sections or typed body blocks instead of HTML. See [Build a page](building-pages.md). |
| Publishing Studio and workspaces | a team needs staged, approved releases of many pages at once. |
| Peek previews and scratch recovery | you want to preview unsaved edits or recover a lost form; both are explained in the [glossary](../reference/glossary.md#editing-terms). |
| Interactions, Inertia, Vue or React | a theme or package needs client-side behaviour beyond Blade. See the frontend track below. |
| AI-ready Capell and SiteSpec | you want an agent to describe or build a site deterministically. |
| Render hooks, admin bridges, extenders, registries | you are writing a package. See the developer track below. |
| HTML cache and fragment cache | the site is live and page speed is the next problem. See [Performance](../performance/README.md). |
| Install suites and recommendations | you are repeating installs across projects. See [Useful installer options](install.md#useful-installer-options). |
| Paid Marketplace packages | the catalogue lists a maintained package for a need you have. See [Packages and extensions](../packages/catalog.md). |

## Choose your next track

| Track | Start | Then |
| --- | --- | --- |
| Editor or site owner | [Admin interface](../admin/interface.md) | [Set up navigation](first-session.md#set-up-navigation-after-your-first-page), [Media management](../admin/media-management.md), [Users and roles](../admin/users-and-roles.md) |
| Site builder | [Packages and extensions](../packages/catalog.md) | [Useful next extensions](create-your-first-page.md#useful-next-extensions), [Music store example](../examples/music-store-cms.md) |
| Package developer | [Core relationship map](../reference/core-erd.md) | [Frontend guide](../frontend/guide.md), [Creating Capell packages](../packages/README.md), [Extension point chooser](../packages/extension-point-chooser.md), [Package authoring](../platform/package-authoring.md), [Build an extension end to end](../packages/build-extension-end-to-end.md), [AI-ready Capell](ai-ready.md) |
| Frontend or Inertia | [Frontend](../frontend/index.md) | [Capell Interactions](capell-interactions.md), [Capell Inertia runtime](inertia-runtime.md), [Inertia widgets](inertia-widgets.md), [Inertia Bookings showcase](inertia-bookings-showcase.md), [approved packages](../packages/catalog.md) |
| Operator | [Going live](../operations/going-live.md) | [Operations](../operations/index.md), [Backups](../operations/backups.md), [Upgrading](../operations/upgrading.md) |

## Next

- [Why Capell](why-capell.md) starts stage 1.
- [Glossary](../reference/glossary.md) when a term on any stage is new to you.
