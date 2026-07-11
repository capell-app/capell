# Seeding content programmatically

![Capell Seeding content programmatically screenshot](../images/admin-dashboard.png)

> **Status:** Skeleton — sections below are outlines. Contributions welcome.

## Scope

How to create Capell content — pages, URLs, translations, element types, element instances, layouts — from seeders or migrations, without going through the admin UI. Primary audience: deployers who want reproducible installs and app authors replacing the default demo data.

## When to seed vs. migrate vs. use the admin UI

- Seeders: reproducible local content, starter kits, test fixtures.
- Migrations: schema-like changes that must run once per environment (e.g. creating a default site on upgrade).
- Admin UI: day-to-day editorial changes by humans.

## Minimum viable site seeder

- Create a `Site` with a `SiteDomain` and a default `Language`.
- Create a `Workspace` if you plan to stage content.
- Create a home `Page` with a `PageUrl` + `Translation`.
- Canonical example: [`capell-app/database/seeders/MarketingPagesSeeder.php`](https://github.com/capell-app/capell-app/blob/main/database/seeders/MarketingPagesSeeder.php).

## Recipes (to be filled in)

### Recipe: add a translated page to every existing site

- Walk `Site::all()`.
- For each site, `Page::firstOrCreate(...)` + attach `Translation` + `PageUrl`.

### Recipe: register an element type + instance

- `Type::firstOrCreate` with `type=element`.
- `Element::firstOrCreate` pointing at the type.
- See the ContentSections package docs for the full element flow.

### Recipe: create a layout with two sections

- `Layout::create(...)`, then define one entry per section in the layout's `containers` array (a JSON column on `Capell\Core\Models\Layout`; there is no separate container model).
- Attach elements in each container entry's `widgets` array.

### Recipe: seed a navigation tree

- `Navigation::firstOrCreate` with the desired type (main, footer, sub-footer).
- Add `NavigationItem` rows keyed to pages by `pageable_type + pageable_id`.

## Running seeders during deploy

- `php artisan db:seed --class=YourSiteSeeder` after `php artisan migrate --force`.
- Laravel Forge / Envoyer / Spin hook examples.

## Seeding approved extensions

The Capell [marketplace](../../packages/marketplace/docs/overview.md) app keeps the first-party extension catalogue in `database/seeders/MarketplaceExtensionSeeder.php`. That seeder should be updated whenever a package becomes Capell-approved so the marketplace, package registry, and docs agree on:

- display name;
- Composer package name;
- extension kind;
- short description;
- pricing;
- product group;
- tier;
- bundle key;
- capabilities.

Run it in the marketplace app with:

```bash
php artisan db:seed --class=MarketplaceExtensionSeeder
```

The seeder marks removed first-party extensions as hidden, so keep the list complete rather than only adding the latest package.

## Pitfalls

- **Morph map.** Any pageable model must register its alias in the service provider before the seeder runs.
- **PublishingStudio.** Rows inserted without a `workspace_id` land in the live version (workspace 0). If you seed draft content, set `workspace_id` explicitly.
- **Cache.** Run `php artisan capell:cache:clear-pages` (or equivalent) after seeding if your app is hot.

## Related

- [PublishingStudio](https://docs.capell.app/publishing-studio/)
- [Multi-site, multi-language](../../packages/core/docs/multi-site-multi-lingual.md)
- [Page management](../../packages/core/docs/page-management.md)
