---
applyTo: 'packages/core/**/*.php,packages/admin/**/*.php,packages/frontend/**/*.php,packages/marketplace/**/*.php'
---

# Capell Core Boundaries

- Core owns models, type/schema registration, package setup, multi-site/language state, settings, migrations, and neutral extension points.
- Admin owns Filament UI. Frontend owns public rendering, route resolution, page cache, theme lookup, and visitor output.
- Core must not import Admin, Frontend, Marketplace, or companion package classes. Use events, interfaces, config, cache/filesystem paths, or string command names when coordination is needed.
- Use extension points before modifying stable internals: `CapellCore::registerPageType`, `CapellCore::registerSchema`, `PageSchemaExtender::TAG`, `SettingsSchemaRegistry`, `RenderHookRegistry`, and lifecycle subscribers.
- New core migrations must also be registered in `packages/core/src/Concerns/HasMigrations.php` when that package pattern applies.
- Frontend/public output must not leak admin/editor markers, package internals, model IDs, field paths, permissions, or signed editor URLs.
