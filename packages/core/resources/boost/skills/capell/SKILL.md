---
name: capell
description: Use when editing or reviewing Capell CMS core, admin, frontend rendering, page types, schemas, caching, or extension points.
---

# Capell CMS

Use this skill for Capell-specific architecture. Keep context small: read only the reference file that matches the task.

## Defaults

- `declare(strict_types=1);` in PHP files.
- PHP 8.4 compatible code: typed class constants are allowed; avoid PHP 8.5+ syntax.
- Explicit parameter and return types, including closures.
- Descriptive names; no single-letter or cryptic variables.
- User-facing strings use translations. Filament labels use method overrides.

## Architecture

- Domain behaviour belongs in Actions under `packages/{pkg}/src/Actions`.
- UI, resources, controllers, commands, and Livewire delegate to Actions.
- Use Data objects for request, form, wire, API, and JSON-cast boundaries.
- New core migrations must be registered in the core package's `src/Concerns/HasMigrations.php` (Composer installs: `vendor/capell/core/src/Concerns/HasMigrations.php`).

## Public Output Safety

- Anonymous and non-admin output must never reveal authoring HTML, scripts, markers, model IDs, field paths, labels, selectors, permissions, package names, or signed editor URLs.
- Public Blade must not query the database or lazy-load relationships. Pass hydrated render data in.
- Rendering, cache, theme, or beacon changes need tests proving anonymous and non-admin safety.

## Extension Points

- Page types: `CapellCore::registerPageType(new PageTypeData(...))`.
- Component aliases: `CapellCore::registerComponent()` / `registerComponents()`.
- Model morph aliases: `CapellCore::registerModel()`.
- Model behavior: `CapellCore::registerModelInterceptor()`.
- Form fields: `PageSchemaExtender::TAG`.
- Table queries: `PageTableExtender::TAG`.
- Lifecycle/subscribers: `CapellCore::subscriberManager()->subscribe(...)`.
- Admin events: `AdminEventRegistry::register(...)`.
- Blade hooks: `RenderHookRegistry::register(RenderHookLocation::X, ...)`.
- Settings: `SettingsSchemaRegistry::register()` and `registerSettingsClass()`.
- Cache dependencies: `CacheInvalidationRegistry::registerDependency()`.
- Package-owned SiteSpec blocks: implement `SiteSpecApplier` and register it with `SiteSpecApplier::TAG`.
- Never use `php artisan capell:admin-publish-schemas`.

## SiteSpec Import

- Core owns deterministic SiteSpec validation and import; commercial packages own AI generation and provider calls.
- Import a local JSON contract with `php artisan capell:site-spec-import path/to/site-spec.json`.
- Navigation is applied by an installed package through the `navigation` SiteSpec applier.
- Remote logo and page images must use the declared HTTPS origin and pass public-DNS, size, and image-type checks.
- Full contract, registration, and security details live in `references/site-spec.md`.

## Fresh Demo Install

- To verify a fresh demo setup, run `php artisan capell:install --fresh=force --demo`.
- `--fresh` without `=force` prompts for destructive confirmation and defaults to no in non-TTY runs.
- For prompt-free agent runs, include required prompt options: `--url=<url>`, `--package-mode=all` or `--packages=...`, `--theme=foundation`, `--name=...`, `--email=...`, `--password=...`, `--clear-cache`, and `--install-welcome-route`.
- Full details and option mapping live in `references/commands.md`.

## References

- `references/architecture.md`: schema, models, routing, morph map, bootstrap order.
- `references/extending.md`: full extension point examples.
- `references/commands.md`: Capell commands and cache operations.
- `references/site-spec.md`: deterministic import contract and package-owned appliers.
- `references/testing.md`: Pest patterns and integration coverage.

## Verification

- Test Actions directly where possible.
- Start with the narrowest Pest file/package, then broaden for shared contracts or public rendering.
- Common checks: `composer test`, `composer preflight`, `composer lint`, `composer analyze`.
