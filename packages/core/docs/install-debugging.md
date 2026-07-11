# Install Debugging

![Capell Install Debugging screenshot](./images/screenshots/core-page-structure.png)

Capell installs are split into two responsibilities:

- Install commands own schema state. They publish migration files, remove stale migration records during `--fresh`, run database migrations, publish settings migrations, and run settings migrations before setup code touches models or settings.
- Setup commands own data state. They create default languages, sites, themes, layouts, pages, roles, permissions, and package-specific seed data after the required schema exists.

Keep this boundary strict. If a setup command has to work around a missing table, missing settings row, or stale migration record, the install command has already leaked schema responsibility into data setup.

## Fresh Install Migration State

`php artisan capell:install --fresh` must clean both the published migration files and the matching rows in the `migrations` table for every installer-owned migration.

This includes:

- Capell core migrations, such as `create_pages_table`, `create_layouts_table`, and `create_page_urls_table`.
- Capell package migrations discovered from registered package paths, including symlinked local package checkouts.
- Vendor migrations that the installer publishes directly, such as Spatie settings, permissions, activity log, and media library migrations.

If migration files are deleted manually but the rows remain in the `migrations` table, Laravel believes the migration has already run. The next install can then skip recreating tables and fail later with errors like:

```text
SQLSTATE[42S02]: Base table or view not found: 1146 Table '...pages' doesn't exist
SQLSTATE[42S02]: Base table or view not found: 1146 Table '...media' doesn't exist
```

The fix belongs in fresh-install preparation: delete stale migration rows for installer-owned migrations before publishing and running migrations again.

## Logical Migration Names

Always compare migrations by logical name, not by full timestamped filename. A migration published as `2026_05_01_071016_08_create_pages_table.php` and a source migration named `create_pages_table.php` represent the same install-owned migration.

When matching migration files or rows:

- Strip Laravel timestamps and Capell sequence suffixes before comparison.
- Treat `2026_05_01_071016_08_create_pages_table` as `create_pages_table`.
- Delete any row whose stripped name matches an installer-owned migration, even if the corresponding published file has already been deleted.
- Keep unrelated application migrations alone.

This keeps `--fresh` safe for host app migrations while making it robust after a developer manually deletes generated Capell migration files.

## Package Migration Discovery

Do not rely only on `vendor/capell-app/*/database/migrations/*.php` globs. Local package development often uses Composer path repositories or symlinks, and PHP glob behavior can miss those package directories depending on the install shape.

Prefer registered package metadata:

- Use `CapellCore::getPackages(withoutCore: false)` to include core and add-on packages.
- Inspect each package `path` for `database/migrations/*.php`.
- Fall back to the vendor glob only if no registered package paths are available.

This matters because package migrations from optional packages must be visible before Laravel refreshes or runs the database.

## Fresh Installs

During `--fresh`, Capell delegates the reset to Laravel with `migrate:fresh`. Do not add package-specific truncation or protected-table preservation here; the command is a full database refresh before setup starts.

## Package Selection Prompts

If an install appears to freeze at `Would you like to install any extra extensions?`, first confirm whether the command is waiting at the interactive package checklist or has moved on to package installation work.

Enable package-selection debug logging in the host app:

```env
CAPELL_INSTALL_DEBUG_PACKAGE_SELECTION=true
```

Then rerun the install and check the Laravel logs:

```bash
grep "capell.install.package-selection" storage/logs/laravel.log
```

The log entries show the resolved package mode, whether Artisan was interactive, the package-related options passed to the command, the packages shown in each checklist, the default selections, and the final expanded package list. For this symptom, check the `prompting for extra packages` entry:

- `default: []` means no extra extensions were preselected; the command is waiting for the operator to press Enter or choose packages.
- A populated `default` list means those extra extensions were preselected. Pressing Enter accepts them and may start Composer/package setup for every selected extension.
- `package_mode_option`, `packages_option`, and `all_packages_option` explain why the installer used that selection path.

Turn `CAPELL_INSTALL_DEBUG_PACKAGE_SELECTION` off after the incident. The entries include package names and install options, which are useful for support but too noisy for normal production logs.

## Settings Migrations

Settings migrations are schema/bootstrap state, not setup data. Package setup code must not read or save a settings class until that package's settings migrations have been published and migrated.

For Spatie Laravel Settings versions that load settings migrations through Laravel's migrator, run them as normal migrations:

```bash
php artisan migrate --path=database/settings --force
```

Do this before calling code that resolves or saves settings classes such as `AdminSettings`.

Package setup commands that can be called directly should either:

- Publish and run their own settings migrations before touching settings-backed code.
- Or fail clearly with an install-order error.

They should not silently create missing settings values inline. Inline defaults hide the real install-order bug and can drift from the migration defaults.

## Command Boundaries

Keep install command flow in this order:

1. Prepare fresh state, including stale migration files and rows.
2. Prepare Laravel environment, such as storage links and framework migration stubs.
3. Publish vendor migrations that Capell owns.
4. Run vendor/database migrations.
5. Resolve or create the install user.
6. Publish Capell core migrations and settings migrations.
7. Run Capell migrations and settings migrations.
8. Run package install commands that publish package migrations/settings.
9. Run migrations again.
10. Run package setup commands for data/configuration.
11. Run demo content.
12. Clear caches and run optional static/sitemap build steps.

Setup commands should assume the install command has already made tables and settings available. Their job is to create records and wire features together: sites, languages, layouts, default pages, roles, permissions, and package data.

## Extension Activation

Composer availability and Capell activation are separate states. A package can be present in `vendor` or a path repository and still be inactive. Runtime providers load only when the package has `capell_extensions.status = enabled`.

During install:

- Mark a package `installing` before running its install command.
- Keep runtime providers inactive while `installing`.
- Mark it `enabled` only after the install command succeeds.
- Mark it `failed` and store `metadata.install_error` when the install command fails.
- Leave `disabled` and `failed` packages visible to management tooling but inactive at runtime.

If an optional package causes missing-table errors before being enabled, check provider bucket placement. Installer-safe commands belong in `providers.install`; admin resources, render hooks, middleware, and model behaviour belong in runtime/admin/frontend buckets.

Demo commands should assume setup completed successfully. If demo content fails with a missing base table or missing settings row, debug the install/setup boundary first.

## Queues And Serialized Models

Fresh installs can fail because stale queued work still references models from the previous schema/data state. Media conversion jobs and queued Actions can serialize model identifiers such as old `media`, `layouts`, or `pages` IDs. If those jobs are processed while `--fresh` is dropping and rebuilding tables, Laravel may throw missing-table or missing-model exceptions during job unserialization.

Treat queue state as install state:

- Stop queue workers before running `capell:install --fresh`.
- Clear pending jobs for database-backed queues before rebuilding Capell data.
- Clear failed jobs if they reference stale Capell models.
- Restart queue workers only after migrations, setup, and demo content have completed.

Installer logic should also avoid dispatching asynchronous jobs during the fresh rebuild where possible. If package setup or demo generation must create media conversions, prefer synchronous conversion in install mode or defer queue processing until after schema and data are stable.

If an error mentions `PerformConversionsJob::__unserialize()` or `JobDecorator::__unserialize()`, inspect queue state before chasing the seeder that happened to trigger the job. The failure may be stale serialized work, not missing demo logic.

## Debugging Checklist

When a fresh install fails during demo or setup:

1. Check whether the missing table has a stale row in `migrations`.
2. Check whether the migration file was deleted or skipped because Laravel thought it had already run.
3. Confirm `--fresh` removes both the file and migration row for that logical migration name.
4. Confirm install commands run database migrations before resolving package setup commands.
5. Confirm package settings migrations run before any setup action reads or saves package settings.
6. Stop queue workers and clear stale queued or failed jobs that serialize old Capell models.
7. Only debug demo content after schema, settings, and queue state are known good.
