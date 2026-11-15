---
name: capell-cms-development
description: 'Builds site content and rendering in a Capell application: converting static routes and Blade views into CMS records, seeding pages, layouts, translations, widgets and sections, and installing packages. Use when converting a Laravel or Blade site to Capell CMS content, seeding content, or debugging seeded content.'
---

# Capell CMS Development

Use this for application-level Capell work: converting static routes and views into CMS records, wiring public rendering, installing Capell packages and debugging seeded content. For architecture, extension points and commands, use the `capell` skill.

## Start here

1. Run Laravel Boost `application_info` and a version-specific `search_docs` before changing code.
2. List installed Capell packages with `composer show 'capell-app/*'` and Capell commands with `php artisan list --raw | grep '^capell'`. Read the installed source under `vendor/capell-app/` when a package's docs are missing.
3. Inspect the schema with Boost `database_schema`. Do not assume LayoutBuilder, Block Library or other package tables exist.
4. Preserve routes that are not pages: authentication, dashboards, webhooks, checkout and API endpoints.

## Decision path

- **LayoutBuilder installed:** seed `Blueprint` and `Widget`, update `Layout::$containers`, attach widgets with `AddWidgetToLayoutContainerAction`, and create `WidgetAsset` rows for page-specific or shared content.
- **LayoutBuilder absent but acceptable:** add the required Capell packages, run their install commands and migrations, then seed CMS data.
- **LayoutBuilder absent and should stay absent:** use custom Blade components, Block Library content, render hooks, page type schemas or ordinary Blade composition.
- **Static Blade conversion:** move public copy, media and configuration into translations, sections, widget assets, content blocks or lang files. Templates render data and hold no final copy.

## Stable keys

When a CMS key is referenced in more than one layer, define a backed enum before the string spreads. Good candidates are layout keys, theme keys, page type keys, widget keys, section keys, section kinds, render variants, and meta keys used by seeders, Blade, tests or editor code. Keep visitor-facing copy out of enums.

## References

- For idempotent page, type, layout and translation seeders, read `references/cms-seeding.md`.
- For LayoutBuilder tables, widget attachment, sections, widget assets and rendering, read `references/layout-builder.md`.

## Verification

Every change needs a programmatic check. Prefer focused Pest tests that seed content and assert that public routes render through Capell.

```bash
vendor/bin/pint --dirty --format agent
php artisan test --compact --filter=<RelevantTest>
```

After installing packages or changing migrations, also run `php artisan capell:doctor` and a route smoke check such as `php artisan route:list --except-vendor`.
