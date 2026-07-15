# Capell Core

## What This Package Adds

**Available. Foundation package. Schema-owning.**

Capell Core provides the shared domain layer for the package-based CMS foundation. It owns the records and contracts that Admin, Frontend, Installer, Marketplace, and optional Capell packages build on.

After install, developers have the models, migrations, settings support, package registry, extension contracts, install actions, upgrade actions, cache helpers, and console tooling needed to run a Capell application. Admin and editor screens are contributed by `capell-app/admin`; Core supplies the records and extension points those screens use.

Core extends these Capell surfaces:

- Admin data records for pages, sites, languages, themes, layouts, media, permissions, redirects, extension state, upgrade state, and public render contract events.
- Frontend render context through sites, page URLs, themes, layouts, translations, and content graph records.
- Console operations for install, doctor checks, package cache, extension install/uninstall, migration publishing, makers, and upgrades.
- Package integration through `capell.json`, `CapellCore`, settings classes, subscribers, and extension registries.

## Why It Matters

- **For developers:** Core is the stable Laravel boundary for content records, migrations, package metadata, upgrade orchestration, and extension points. It gives package developers typed models, Actions, Data objects, settings support, cache helpers, and registry contracts instead of requiring direct changes to the host app.
- **For teams:** Core keeps the CMS foundation predictable. Editors work in Admin, public pages render through Frontend, and site operations use one shared set of content, package, and upgrade records.

## Screens And Workflow

![Capell page structure](images/screenshots/core-page-structure.png)

![Capell settings-backed configuration](images/screenshots/core-settings-backed-configuration.png)

Screenshot contract:

- Admin index screen: shown through Admin resources that read Core records, especially Pages, Sites, Layouts, Themes, Media, and Extensions.
- Create/edit screen: shown through Admin resources; Core owns the records and validation contracts, not the Filament pages.
- Settings/configuration screen: available through settings-backed configuration.
- Frontend output: rendered by Frontend from Core page, site, language, theme, layout, and translation records.
- Package detail or install intent screen: owned by Marketplace, using Core package metadata and extension records.
- Carousel steps: not applicable for Core.

Docs gap: Core does not currently have a standalone screenshot that isolates each Core-owned model index. The Admin package screenshots prove the main editor-facing surfaces.

## Technical Shape

- Service provider: `Capell\Core\Providers\CapellServiceProvider`.
- Config: package and registry configuration loaded through the Capell package system.
- Migrations: Core registers schema migrations through `HasMigrations`.
- Settings: locale, theme studio, and image-source settings are installed from `database/settings`.
- Models: pages, sites, languages, layouts, themes, blueprints, media, URLs, redirects, translations, extension state, upgrade state, deletion batches, content locks, and render contract events.
- Actions and Data objects: install, upgrade, package discovery, content graph, rendering support, theme studio, deletion, and package lifecycle work.
- Console commands: `capell:install`, `capell:doctor`, package cache commands, extension install/uninstall commands, migration publishing, makers, and upgrade/rollback commands.
- Policies and permissions: Core owns permission tables and content restrictions used by Admin policies.
- Events/listeners: lifecycle and subscriber contracts are registered for package extension.
- Cache behaviour: package registry cache, component cache, content cache helpers, and cache invalidation contracts are centralised here.
- Extension hooks: page types, schemas, settings, subscribers, static-site export hooks, cache dependencies, render hooks, and Tailwind asset registration are built on Core contracts.

## Data Model

Core owns schema migrations for the main Capell records:

- `languages`, `sites`, `site_domains`, `blueprints`, `themes`, `layouts`, `pages`, `page_urls`, and `translations`.
- `media`, `asset_attachments`, `asset_relations`, and content graph records.
- Redirect, public render contract, and health records used by site operations.
- Permission/team columns and page role restrictions used by admin access control.
- `capell_extensions`, `capell_marketplace_installs`, extension health alerts, upgrade runs, upgrade events, deletion batches, and content locks.

Main relationships:

- Sites own domains, languages, pages, layouts, themes, URLs, translations, and render context.
- Pages connect to site, language, parent page, blueprint/type, layout, URLs, media, translations, locks, and content graph edges.
- Packages connect through extension records and package manifests rather than direct model inheritance.

Migration impact:

- Installing Core creates the base CMS schema.
- New Core migrations must be added to `HasMigrations::getMigrations()`.
- Settings migrations live under `database/settings` and must guard existing records.

Deletion and retention:

- Deletion batches and deletion batch records track destructive content operations.
- Content locks protect active editing work.
- Audit and upgrade records are retained for operational review.

## Install Impact

- Admin navigation: no direct navigation by itself; Admin registers the Filament resources that use Core records.
- Permissions: creates and extends permission-related schema used by Admin and package policies.
- Public routes: no public routes are registered by Core.
- Database changes: creates the base Capell schema and Core settings.
- Config keys: package registry, cache, install, upgrade, and settings contracts are read through Capell package configuration.
- Queues or scheduled tasks: Core provides queued Actions/jobs used by install, upgrade, cache, and package lifecycle work.
- Cache tags or invalidation paths: package registry cache, component cache, content cache helpers, and registered dependency patterns.

## Common Pitfalls

- Missing Core migrations leave Admin and Frontend unable to resolve sites, pages, or package state.
- Adding a Core migration without updating `HasMigrations` breaks package installation and upgrades.
- Writing directly to models instead of Actions can skip cache invalidation, content graph updates, or package lifecycle rules.
- Treating Core as an admin UI package creates the wrong ownership boundary; Admin owns Filament screens.
- Public Blade must not query Core models directly. Hydrate public render data before the view.
- Package metadata must come from installed manifests and Composer state, not hard-coded package lists.

## Quick Start

1. Install the package with `composer require capell-app/core`.
2. Run setup with `php artisan migrate` and the Capell install or upgrade command used by the host app.
3. Verify the result with `php artisan capell:doctor` and open the Admin package surfaces that read Core records.

## Next Steps

- [Page management](page-management.md)
- [Content management](content-management.md)
- [Extending Capell](extending-capell.md)
- [HasCache guide](cache.md)
- [Multi-site and multi-lingual](multi-site-multi-lingual.md)
- [Relationship diagnostics](relationship-diagnostics.md)
- [Subscriber manager](subscriber-manager.md)
- [Static-site extensions](static-site-extensions.md)
- [Authoring upgrade steps](authoring-upgrade-steps.md)
- [Install debugging](install-debugging.md)
