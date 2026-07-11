# Capell Frontend

## What This Package Adds

**Available. Foundation package. No schema impact for package tables.**

Capell Frontend renders public Capell pages. It resolves the current site, page, language, theme, layout, render profile, frontend settings, static cache state, render hooks, and frontend assets for ordinary public requests.

After install, site visitors can load published pages through Laravel routes, and admins can configure frontend behaviour through the package settings surface. Frontend includes a minimal built-in `default` Blade theme fallback; opinionated themes, chrome, widgets, SEO, sitemap output, and authoring overlays belong to optional packages.

Frontend extends these Capell surfaces:

- Public Laravel routes for home, page fallback, widget fragments, and static output support.
- Public rendering through Blade, Livewire page support, render hooks, frontend runtime resolution, and theme view registration.
- Site operations through static HTML cache support, cache invalidation contracts, and Tailwind asset aggregation.
- Package integration through frontend asset contributors, render hooks, presentation delivery, and public-safe view helpers.

## Why It Matters

- **For developers:** Frontend is the boundary between Capell content records and public HTML. It provides request loading, render context, theme resolution, cache behaviour, Tailwind source registration, public safety contracts, and extension points for packages.
- **For teams:** Visitors get stable public pages while editors can keep working in Admin. Site operations can verify cache state, theme readiness, and public output safety without exposing admin-only behaviour.

## Screens And Workflow

![Published Capell frontend page](images/screenshots/frontend-published-page.png)

![Frontend settings in the Capell admin](images/screenshots/frontend-settings.png)

Screenshot contract:

- Admin index screen: not owned by Frontend. Settings are shown in Admin.
- Create/edit screen: not owned by Frontend. Content editing is owned by Admin and optional authoring packages.
- Settings/configuration screen: Frontend settings page in Admin.
- Frontend output: published page screenshot proves the public rendering path.
- Package detail or install intent screen: not applicable.
- Carousel steps: not applicable for Frontend.

## Technical Shape

- Service provider: `Capell\Frontend\Providers\FrontendServiceProvider`.
- Config: frontend settings, route registration, render strategy, static output, Tailwind assets, cache behaviour, and public HTML safety options.
- Migrations and models: no Frontend-owned tables or models. Settings are installed from `database/settings`.
- Filament resources/pages: settings surfaces are contributed through Admin, not direct Frontend resources.
- Livewire components: public page and widget rendering support where the page type or loaded type requires Livewire.
- Routes: widget fragment route, optional home route, static `index.php` route, and page fallback route.
- Policies/permissions: public rendering does not depend on admin policies; editing and authoring permissions belong to Admin or authoring packages.
- Events/listeners: frontend render and cache lifecycle listeners support invalidation and runtime setup.
- Jobs/queues/schedules: static HTML generation and cache operations use package Actions/jobs where configured.
- Blade views/components: default theme views, public-safe helpers, render hooks, presentation delivery, and widget output.
- Cache behaviour: [static HTML cache](../../../docs/architecture/page-cache.md) support, [page cache invalidation](../../../docs/performance/cache-invalidation.md), fragment cache directives, render runtime cache, and frontend settings lookup.
- Extension hooks: render hook registry, frontend asset contributors, Tailwind source/import registry, presentation delivery, and widget resource registry.

## Data Model

Frontend owns no data tables.

It reads Core records prepared by the request pipeline:

- Site, domain, language, page URL, page, layout, theme, translation, and blueprint/type records.
- Frontend settings records installed by the package.
- Cache and runtime state derived from the current request and package configuration.

Migration impact:

- No Frontend schema migrations.
- One settings migration creates Frontend settings.

Deletion and retention:

- Frontend does not own content retention.
- Cache invalidation must clear stale public output when Core or package content changes.

## Install Impact

- Admin navigation: adds Frontend settings through Admin settings contributors.
- Permissions: no public permissions added by Frontend.
- Public routes: registers widget fragments, optional home route, static `index.php`, and page fallback routing.
- Database changes: installs Frontend settings only.
- Config keys: route registration, URL regex, cache, static output, Tailwind assets, render strategy, and settings behaviour.
- Queues or scheduled tasks: static generation and cache jobs may run when configured.
- Cache tags or invalidation paths: page, site, theme, layout, asset, and package dependency paths feed public cache invalidation.

## Common Pitfalls

- Public Blade views must not run database queries, lazy-load relationships, or expose admin/editor metadata.
- Frontend authoring must be post-load and admin-only; public cached HTML must remain safe for anonymous visitors, users, admins, crawlers, and static exports.
- Missing route registration or a restrictive URL regex can make published pages 404.
- Cache state can hide template changes until the correct invalidation path or cache clear runs.
- Livewire assets should be loaded only when the loaded page type requires them.
- SEO and discovery concerns belong to `capell-app/seo-suite` and `capell-app/site-discovery`, not Frontend.

## Quick Start

1. Install the package with `composer require capell-app/frontend`.
2. Run setup with `php artisan migrate` and clear cached config/routes if the host app was already booted.
3. Open a published page and verify the Frontend settings surface in Admin.

## Next Steps

- [Page and site loading](page-site-loading.md)
- [Public HTML safety](../../../docs/frontend/public-html-safety.md)
- [Extending render hooks](extending-render-hooks.md)
- [Frontend extensions](../../../docs/packages/frontend-extensions.md)
- [Presentation delivery](presentation-delivery.md)
- [Capell Interactions](../../../docs/getting-started/capell-interactions.md)
- [Server config](server-config.md)
- [Tailwind assets](tailwind-assets.md)
- [Testing frontend](testing-frontend.md)
- [Security](security.md)
- [Lazy page hydration](../../../docs/performance/lazy-page-hydration.md)
