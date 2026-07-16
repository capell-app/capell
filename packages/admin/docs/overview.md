# Capell Admin

## What This Package Adds

**Available. Foundation package. No schema impact for package tables.**

Capell Admin provides the Filament admin surface for Capell. It turns Core records into editor and operator screens for pages, sites, media, themes, users, permissions, reports, settings, package state, and site operations.

After install, admins and editors can manage content, review site health, configure package settings, manage installed extensions, inspect activity, run reports, and use package-contributed tools from one Filament panel.

Admin extends these Capell surfaces:

- Filament resources for Core records, including pages, sites, languages, layouts, themes, media, URLs, redirects, roles, users, activity, and extensions.
- Filament pages for dashboard, settings, reports, site health, sitemap, upgrade, marketing studio, and extension management.
- Admin header tools, user menu items, dashboard Filament widgets, resource actions, schema hooks, settings schemas, and report pages.
- Admin-facing package settings through the settings schema registry.

## Why It Matters

- **For developers:** Admin is the package boundary for Filament resources, schema extension, policies, settings pages, dashboard Filament widgets, and admin events. Packages extend Admin through documented registries instead of editing core resources.
- **For teams:** Editors get a consistent publishing workflow, media library, settings surface, reports, and operational checks without each package inventing its own admin pattern.

## Screens And Workflow

![Capell admin dashboard](images/screenshots/admin-dashboard.png)

![Capell pages list](images/screenshots/admin-pages-list.png)

![Capell media library](images/screenshots/admin-media-list.png)

![Capell Site Health page](images/screenshots/site-health-page.png)

Screenshot contract:

- Admin index screen: dashboard, Pages index, Media index, Extensions index, and package reports.
- Create/edit screen: Pages create/edit, Media edit, Sites, Layouts, Themes, Users, Roles, and other resources.
- Settings/configuration screen: package-aware settings pages and settings schema contributors.
- Frontend output: not rendered by Admin. Preview and source-map actions hand off to Frontend or authoring packages.
- Package detail or install intent screen: Admin exposes extension entry points; Marketplace owns marketplace detail and install intent screens.
- Carousel steps: not applicable for Admin.

## Technical Shape

- Service provider: `Capell\Admin\Providers\AdminServiceProvider`.
- Filament panel: `AdminPanelProvider` registers the Capell panel, resources, pages, widgets, middleware, auth, avatars, and plugin integration.
- Config: admin settings, dashboard settings, report visibility, header navigation, and package extension registries.
- Settings migrations: Admin settings, header navigation tree, configurator path hints, and report visibility.
- Filament resources/pages: page, site, language, layout, theme, media, URL, redirect, role, user, activity, extensions, settings, reports, site health, sitemap, upgrade, and dashboard pages.
- Livewire components: command palette, info banner, header navigation tree, and admin tools.
- Routes: signed theme preview, page-tree API, profile language update, SVG avatars, and protected extension asset delivery.
- Policies/permissions: role and permission sync actions, Shield integration, default role permissions, and resource policies.
- Events/listeners: admin lifecycle events, event registry, dynamic event listeners, and activity actions.
- Jobs/queues: upgrade notification and admin operation jobs where required.
- Blade views/components: Filament pages, settings views, reports, widgets, and admin-only assets.
- Extension hooks: tool registry, user menu registry, resource registration, schema hooks, dashboard Filament widgets, settings schema registry, report registration, and resource header actions.

## Data Model

Admin does not own the main CMS schema.

Admin connects to Core records and package records through Filament resources:

- Core content records: pages, sites, languages, media, layouts, themes, URLs, redirects, translations, extensions, and permissions.
- Admin notification subscriptions and failed job inspection models.
- Settings records installed from Admin settings migrations.

Migration impact:

- Admin has settings migrations only.
- Core creates shared tables used by Admin, including admin notification subscriptions.

Deletion and retention:

- Admin delegates destructive content operations to Core Actions and package Actions.
- Activity, audit, failed-job, and notification data are retained by their owning tables and policies.

## Install Impact

- Admin navigation: adds the Capell Filament panel, resource navigation, dashboard Filament widgets, system pages, reports, settings, and extension management.
- Permissions: syncs Capell permissions and default role permissions.
- Public routes: none. Admin routes are authenticated or signed as appropriate.
- Database changes: installs Admin settings records; Core owns the data tables.
- Config keys: admin panel path, settings, report visibility, dashboard Filament widgets, header navigation, and package extension registries.
- Queues or scheduled tasks: upgrade notifications and package-contributed admin jobs may run through the queue.
- Cache tags or invalidation paths: admin widgets, configurators, settings schemas, resources, permissions, and package caches can be cleared by Admin commands.

## Common Pitfalls

- Skipping `php artisan capell:admin-sync-permissions` can hide resources or actions for non-super-admin users.
- Adding user-facing strings directly to Filament classes bypasses translation conventions.
- Adding package UI by editing Admin resources directly makes upgrades harder; use schema hooks, resource registration, or tagged extenders.
- Rendering admin identifiers, field paths, signed editor URLs, or package internals in public output violates frontend authoring safety.
- Testing Admin only at the login screen misses permission, navigation, and policy regressions.
- Dashboard Filament widgets and reports should use Actions for domain work rather than embedding queries in the widget class.

## Quick Start

1. Install the package with `composer require capell-app/admin`.
2. Run setup with `php artisan migrate` and `php artisan capell:admin-sync-permissions`.
3. Open the Capell admin panel and verify the dashboard, Pages resource, Media resource, Settings, and Site Health page.

## Next Steps

- [Admin multi-language](admin-multi-language.md)
- [Admin tool registry](admin-tool-registry.md)
- [Dashboard Filament widget customization](dashboard-widget-customization.md)
- [Event registry](event-registry.md)
- [Header navigation tree](header-navigation-tree.md)
- [Marketing Studio](marketing-studio.md)
- [User resource customization](user-resource-customization.md)
- [Resource registration](resource-registration.md)
- [Settings schema registry](settings-schema-registry.md)
- [Presentation and interactions](presentation-and-interactions.md)
- [User menu registry](user-menu-registry.md)
- [Schema hooks](schemas/hooks.md)
- [Permissions and approval](permissions-and-approval.md)
- [Reports](reports.md)
