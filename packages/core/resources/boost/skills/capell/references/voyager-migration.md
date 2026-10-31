# Capell Voyager Migration Reference

Voyager Importer (`capell-app/voyager-importer`) moves a Voyager site into Capell. It reads Voyager's tables over a Laravel database connection and never loads Voyager classes, so the host can be on a Laravel version Voyager does not support. Execution runs inside Migration Assistant (`capell-app/migration-assistant`), which records the session, result report and rollback report.

The commands below exist only when both packages are installed. Confirm with `php artisan list voyager-importer`. The package docs hold the full runbook: `packages/voyager-importer/docs/migration-guide.md` in the packages repository.

## Contents

- [Commands](#commands)
- [Order of work](#order-of-work)
- [Rules that catch people out](#rules-that-catch-people-out)
- [Not imported](#not-imported)
- [Keeping this file current](#keeping-this-file-current)

## Commands

```bash
php artisan voyager-importer:inventory --connection=voyager --json
php artisan voyager-importer:import --connection=voyager --only=pages,posts --site-id=1 --json
php artisan voyager-importer:import --connection=voyager --source-label=<slug> --only=<entities> --actor-id=<user-id> --execute
php artisan voyager-importer:import --connection=voyager --source-label=<slug> --session=<uuid> --execute
php artisan voyager-importer:make-resource <data_type>
```

`voyager-importer:import` options: `--connection`, `--source-label`, `--repoint-source`, `--new-source`, `--json`, `--only`, `--site-id`, `--locale-map`, `--role-map`, `--post-url-prefix`, `--actor-id`, `--publish-from-source-status`, `--skip-unmapped`, `--url-conflict` (`fail` or `skip`), `--page-layout`, `--page-blueprint`, `--article-layout`, `--article-blueprint`, `--session`, `--execute`, `--force`.

`--only` accepts `users`, `roles`, `settings`, `media`, `pages`, `posts`, `translations`, `menus`, `redirects` and `tables`. Dependency order is applied for you.

## Order of work

1. Restore the Voyager dump into a scratch database and add a Laravel connection for it. Never import from the live database; the importer refuses the default connection and aliases of the destination database.
2. Inventory. It lists BREADs, setting keys (never values), menus and custom tables, and writes nothing.
3. Preview. Without `--execute` the import command only reports planned counts, missing layout or blueprint keys, URL conflicts and roles that need `--role-map`.
4. Execute global entities (users, roles, settings, menus) and site-owned entities (pages, posts, media) as separate runs. They cannot be mixed in one execution.
5. Re-run the same execution. An unchanged source must report no created or updated rows.
6. Resume a failed run with `--session=<uuid>`, the same `--connection` and the same `--source-label`, and no changed mapping options.
7. Verify in the Migration Assistant session. Roll back from there, before editors touch imported content; otherwise restore the pre-import database dump.

## Rules that catch people out

- Execution needs `--execute`, `--only`, `--source-label` and confirmation. Unattended runs and the `production`, `prod` and `live` environments need `--force`.
- The source label is bound to the source database server. Reuse it for the same source; `--repoint-source` and `--new-source` are explicit, recorded exceptions.
- Imported pages and posts are drafts unless `--publish-from-source-status` is passed.
- Voyager roles that match a privileged Capell role (`admin`, `super_admin`) are refused unless mapped with `--role-map` to a non-privileged target.
- Posts need `--post-url-prefix` (for example `/articles`) so source URLs are preserved.
- Populated fields with no Capell mapping fail the import unless `--skip-unmapped` is passed, which records field names, never values.
- Setting values are never printed; keys that look like secrets appear as `[redacted]`.
- Roll back with `migration-assistant:rollback-report <session>`, then `migration-assistant:rollback-execute <session> --actor=<id> --dry-run`, then without `--dry-run`.

## Not imported

- Custom tables (orders, products and similar). Inventory lists them under `custom_tables`; importing rows needs a contributor implementing `VoyagerTableImportContributor`.
- Voyager admin menus, permission groups and category relationships.
- Page images unless mapped or skipped; media must be on the local `public` disk.
- Activity-log entries for quietly saved users and translations.

## Keeping this file current

Refresh this reference whenever the importer's command signatures or entity list change. Check it against `packages/voyager-importer/src/Console/*Command.php` and `php artisan list voyager-importer`.
