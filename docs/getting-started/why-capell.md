# Why Capell

![Capell CMS page administration inside a Laravel application](../images/capell-readme-banner.jpg)

Capell is a Laravel CMS built on Filament. It is for teams that want editors to manage structured pages, URLs, media, layouts, and publishing without moving the product into a separate CMS runtime.

Its strongest practical difference is not another field builder. Capell makes change safer: page edits have append-only history and validated page-only rollback, while package upgrades can be planned, recorded, diagnosed, and rolled back when an upgrade step declares a safe reverse operation.

## The decision in one minute

Choose Capell when:

- the website is part of a Laravel product rather than an isolated publishing site;
- repeated page types, layouts, URLs, and publishing rules should have one maintained shape;
- editors need approved composition while developers retain control of public HTML;
- Composer packages, Actions, Eloquent, queues, tests, and deployment should remain the extension model;
- the team values visible upgrade, health, page-history, and exit contracts.

Choose another approach when:

- a small site only needs a handful of stable editable fields;
- WordPress already has the exact maintained theme/plugin combination the brief needs;
- Statamic's flat-file model and editorial workflow fit better;
- Craft's dedicated content-modelling ecosystem is the reason for the project;
- a hosted no-code CMS or public content-delivery API is a hard requirement.

Capell is not hosted software and does not ship a public content-delivery API. Public pages render through the Laravel application using Blade, Livewire, Inertia, Vue, or the host's own stack.

## What Laravel keeps and Capell adds

| Laravel remains responsible for | Capell adds |
| --- | --- |
| Application domain models and services | Sites, languages, page trees, URLs, layouts, themes, media contracts, translations, and settings |
| Authentication and infrastructure | Filament editor workspace, roles, page publishing, preview, and page recovery UI |
| Queues, cache, scheduler, filesystem, and deployment | Package health, lifecycle, upgrade planning, and CMS-specific diagnostics |
| Public controllers and presentation choices | Site context, public page resolution, render hooks, theme assets, and cache-safe delivery contracts |
| Database and media disaster recovery | Page revision history and page-only rollback |

That final boundary matters: Capell can restore a page revision, but the host application must still back up and restore its database and media.

## Compared with custom Filament

Filament is an excellent admin framework. A custom Filament resource is often the right answer for small, stable CRUD. Capell earns its place when the team is repeatedly building the surrounding CMS system.

| Problem | Custom Filament build | Capell foundation |
| --- | --- | --- |
| Page structure | Design nested pages, moves, slugs, canonical URLs, redirects, and breadcrumbs | Shared page, URL-history, redirect, and move contracts |
| Content recovery | Decide what a revision owns, how to diff it, and how rollback avoids conflicts | Page-owned state history, rollback preview, validation, roll back, and roll forward |
| Multi-site/language | Scope queries, permissions, URLs, settings, cache keys, and translations | Site, domain, language, translation, and URL foundations |
| Editor safety | Build preview, publish state, permissions, cache invalidation, and public-output boundaries | Filament workspace and package extension points over shared CMS rules |
| Upgrades | Every project invents migrations and evidence | Planned upgrade steps, durable logs, diagnostics, and explicit rollback support |
| Extension model | Add project-specific resources and services | Normal Composer packages plus Capell manifests and registries |

Use custom Filament when CRUD will stay small. Use Capell when these page concerns have become a maintained product inside the Laravel application.

## Compared with Statamic

Statamic is a strong CMS when its flat-file model, control panel, and ecosystem match the project. Capell fits more naturally when content must participate directly in an existing Laravel application's relationships, transactions, permissions, queues, and deployment.

| Question | Statamic-shaped fit | Capell-shaped fit |
| --- | --- | --- |
| Primary content model | Flat files and Statamic collections are desirable | Database-backed Laravel models and relationships are desirable |
| Product boundary | The CMS can be the centre of the site | The CMS must live inside a broader Laravel product |
| Extension model | Statamic add-ons and Antlers/Twig conventions fit the team | Composer packages, Filament, Actions, Blade/Livewire/Inertia fit the team |
| Operations | The team wants Statamic's established workflow | The team wants Capell's page-history and package-upgrade contracts inside Laravel |

Neither choice is automatically better. The cheaper long-term boundary is the one the team can operate, test, upgrade, and exit confidently.

## Compared with WordPress and Craft

WordPress is usually the fastest choice when its mature plugin and theme ecosystem already solves the brief. Craft is a strong choice for bespoke content-led sites that benefit from its established commercial CMS and control panel.

Capell is the stronger fit when sharing Laravel's runtime and domain services removes more integration work than those ecosystems save. Read the detailed [WordPress and Craft comparison](comparing-capell.md) before deciding.

## The editor/developer contract

Editors work with shared page types, layouts, approved widgets, assets, preview, publishing, and history. Developers define the permitted structure and keep ownership of the public output.

![Capell page editor with structured content and publishing context](../images/generated/admin/admin-page-edit-form.png)

This avoids two common extremes: every content change becoming a developer ticket, or a visual builder allowing every page to become a one-off design. Capell supports custom pages, but repeated content should use a repeatable structure when that makes future change cheaper.

## Packages without catalogue fiction

The public foundation consists of Core, Admin, Frontend, Installer, and Marketplace. Optional capabilities may exist as Released, Beta, Labs, private, or source-only packages.

Do not infer availability from a documentation page or source directory. Before adopting an optional package, verify:

- the exact Composer distribution path and access requirement;
- supported Capell, Laravel, Filament, and PHP versions;
- maturity and current release evidence;
- migrations, data access, queue/scheduler needs, and public output;
- support, update, licence, expiry, removal, and export terms.

The [package catalogue](../packages/catalog.md) documents contracts, but the live marketplace/account is the authority for what a customer can currently install.

## Operational fit

Capell helps when a team wants explicit answers to these questions:

- What will this upgrade change before we apply it?
- Which upgrade steps are actually reversible?
- Can an editor see and restore a prior page state without deleting history?
- Which health check is red, and what exact command repairs it?
- How do we back up and scratch-restore the database and media?
- How do we export content and leave?

Read [Upgrading](../operations/upgrading.md), [Backups](../operations/backups.md), [Site Health](../operations/site-health.md), and [Export and exit](../operations/export-and-exit.md). A buyer should evaluate these alongside the page editor, not after launch.

## Next step

- [Run the verified quickstart](quickstart.md)
- [Compare WordPress and Craft](comparing-capell.md)
- [Create the first page](create-your-first-page.md)
- [Inspect the package maturity catalogue](../packages/catalog.md)
