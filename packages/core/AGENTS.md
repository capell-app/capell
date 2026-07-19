# Core Package Instructions

- Keep core neutral: no Filament resources, frontend rendering concerns, marketplace clients, or AI-provider dependencies.
- Put domain operations in Actions and structured JSON, request, manifest, and package boundaries in Data objects.
- Treat public contracts, container tags, package capabilities, migrations, and generated catalogues as compatibility surfaces with direct tests.
- SiteSpec import must remain deterministic. Validate explicit inputs, sanitise authored HTML, reject unsafe remote media, and require installed packages to apply package-owned blocks.
- Register SiteSpec handlers with `SiteSpecApplier::TAG`; do not reference companion package models from core.
- New migrations must be registered in `src/Concerns/HasMigrations.php`.
- Use `capell::` translations for user-facing command and validation copy where the surrounding API supports translated messages.
- Start with the affected core Pest files, then run focused PHPStan and the root repository gates.
