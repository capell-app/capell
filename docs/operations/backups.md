# Backups and restore

![Capell Backups and restore screenshot](../images/generated/admin/site-health-page.png)

Use this runbook before upgrades, package installs, schema changes, and high-risk content operations.

Capell stores critical state in the Laravel database, `.env`, and `storage/`. A database dump alone is not enough if media, generated files, logs, or environment secrets are needed for recovery.

## What to back up

| Target                                  | Why it matters                                                                                                                  |
| --------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------- |
| Database                                | Sites, languages, pages, URLs, media records, settings, users, roles, package state, upgrade ledgers, and marketplace state.    |
| `.env`                                  | App key, database credentials, queue/cache/storage settings, Capell admin path/domain, marketplace URLs, and package secrets.   |
| `storage/`                              | Media files, generated assets, logs, framework cache/session files when file-backed, and Capell runtime state such as Lockdown. |
| Configured static HTML output directory | Optional cache/static package output. Usually rebuildable, but useful when restoring public output quickly.                     |
| `composer.lock`                         | The exact package versions that produced the backed-up state.                                                                   |

If the app uses S3 or another external disk for media, back up that disk or verify its own retention policy before relying on a server snapshot.

## Before an upgrade

Run these commands from the Laravel app root on the server or in the deploy environment.

```bash
php artisan down
php artisan optimize:clear
```

Create a database dump with your normal database tool. Example for MySQL-compatible databases:

```bash
mysqldump --single-transaction --routines --triggers "$DB_DATABASE" > "backup-$(date +%Y%m%d-%H%M%S).sql"
```

Archive the Laravel files Capell needs for recovery:

```bash
tar -czf "capell-files-$(date +%Y%m%d-%H%M%S).tar.gz" .env storage composer.lock
```

Bring the app back only when the backup command has completed and the files have been copied to durable storage:

```bash
php artisan up
```

## Restore check

Do not wait for an incident to learn whether a backup works. Restore into staging or a disposable environment and check:

- the database imports without errors;
- `php artisan about` boots with the restored `.env`;
- `php artisan migrate:status` does not show unexpected pending migrations;
- media and uploaded files load from the restored disk;
- `/admin` accepts a known admin account;
- one published page renders from a signed-out browser.

## Restore procedure

For a full restore, take the broken environment offline first:

```bash
php artisan down
```

Restore files, then restore the database using the matching database tool. Example for MySQL-compatible databases:

```bash
tar -xzf capell-files-YYYYMMDD-HHMMSS.tar.gz
mysql "$DB_DATABASE" < backup-YYYYMMDD-HHMMSS.sql
```

Then clear runtime caches and restart workers:

```bash
php artisan optimize:clear
php artisan queue:restart
php artisan up
```

If the restore is for a failed upgrade, run [Site Health](site-health.md) and the upgrade dry-run before attempting the upgrade again:

```bash
php artisan capell:upgrade --dry-run
```
