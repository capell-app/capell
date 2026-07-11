# Capell CMS Context

Last reviewed: 2026-07-08

Capell is a Laravel CMS composed of installable packages. Core owns the content model, package registry, installation and upgrade flows, settings schema registration, makers, static-site generation, and shared extension points. Admin adds the Filament authoring experience. Frontend resolves incoming requests into site, page, layout, theme, assets, render hooks, and cache behaviour.

## Domain Terms

- Site: a web property with a default Language, Theme, domains, settings, and a tree of Pages.
- Site Domain: a host and Language mapping used to resolve multilingual frontend requests.
- Language: a locale available to Sites, Pages, and translated content records.
- Page: a nested content record with a Type, Layout, Site, translations, URLs, publish dates, assets, and revisions.
- Page URL: the routable URL state for a Page, including generated paths and wildcard parameters.
- Type: a configurable content kind for pages, sites, themes, and system records.
- Layout: the structural template used by Pages during rendering.
- Theme: visual configuration, assets, typography, colors, header, footer, and layout defaults.
- Translation: localized field content attached to translatable models.
- Asset: media or frontend resource metadata used by content, admin, and rendering.
- Settings Schema: a package-provided schema that defines editable settings.
- Package: an installable Capell module discovered through package metadata and installed through package workflows.
- Marketplace: the admin acquisition, authorization, account connection, package operation, and deployment handoff surface for installable packages.
- Maker: a code-generation workflow for Actions, Data, schemas, types, extenders, and admin configurators.
- Install Plan: the ordered set of installation steps used by the installer and install guide.
- Frontend Context: the resolved request state for rendering, including URL, Site, Page, Layout, Theme, cache policy, and subscribers.
- Render Hook: an extension point for injecting frontend HTML at predefined locations.
- Cache Invalidation Rule: a model-to-cache dependency mapping used to purge affected frontend output.
- Error Page Cache: static fallback output regenerated from Site, domain, error page, translation, and site logo media changes.

## Package Responsibilities

- `packages/core`: content models, package metadata, install and upgrade orchestration, settings schemas, makers, shared Actions, shared Data, and core extension registries.
- `packages/admin`: Filament resources, form-builder, tables, admin actions, dashboard providers, schema extenders, admin tooling, and admin-specific package setup.
- `packages/frontend`: request resolution, frontend rendering, page, fragment, and error-page caching, render hooks, frontend assets, middleware, and static/page cache invalidation.
- `packages/installer`: temporary browser installer, install guide, preflight checks, package/theme selection, setup progress, reports, and installer cleanup.
- `packages/marketplace`: Marketplace catalogue, connection callbacks, domain trust, install authorization, package operation records, heartbeat/update signals, and Composer/deployment handoff.

## Architecture Rules

- Domain behaviour belongs in Actions; callers use `::run()` or `::dispatch()`.
- Structured state crossing package, HTTP, Livewire, or Filament seams should use Data objects.
- Extension should use registered Capell extension points instead of editing package internals in place.
- Filament labels and user-facing strings should come from translations.
- Database writes should happen through Actions, not model side effects.
- Frontend authoring must be invisible to non-admin users: no authoring markup, metadata, scripts, or signed URLs before an authenticated admin beacon response.
- Package install, uninstall, and Marketplace flows should call the same package-owned Actions so CLI, installer, admin, and deployment handoff paths do not drift.
