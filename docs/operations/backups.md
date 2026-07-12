# Backups and restore

Capell can create versioned database and media snapshots, verify their health,
apply retention, and prove recovery in isolated scratch targets. Backups are
disabled by default and never restore over live data.

This capability protects Capell content. Keep separate infrastructure backups
for `.env`, `APP_KEY`, `composer.lock`, deploy configuration, logs, and any
files not stored on configured media disks.

## Configure backup storage

Configure a private Laravel filesystem disk that is different from every media
source disk. Object storage with independent versioning and lifecycle controls
is recommended for production.

```env
CAPELL_BACKUP_ENABLED=true
CAPELL_BACKUP_DISK=backups
CAPELL_BACKUP_PREFIX=capell-backups
CAPELL_BACKUP_DB_CONNECTION=mysql
CAPELL_BACKUP_MEDIA_DISKS=public,media
CAPELL_BACKUP_MAX_AGE_HOURS=26
CAPELL_BACKUP_MINIMUM_RETAINED=7
CAPELL_BACKUP_RETAIN=30
CAPELL_BACKUP_PROCESS_TIMEOUT_SECONDS=3600
CAPELL_BACKUP_SCRATCH_DATABASE_PREFIX=capell_restore_
CAPELL_BACKUP_SCRATCH_SQLITE_DIRECTORY=/srv/capell/restore-scratch
```

The configured database connection may use SQLite, MySQL/MariaDB, or
PostgreSQL. MySQL/MariaDB hosts need `mysqldump` and `mysql`; PostgreSQL hosts
need `pg_dump` and `psql`. Override binary paths with
`CAPELL_BACKUP_MYSQLDUMP_BINARY`, `CAPELL_BACKUP_MYSQL_BINARY`,
`CAPELL_BACKUP_PG_DUMP_BINARY`, and `CAPELL_BACKUP_PSQL_BINARY` when needed.
Increase `CAPELL_BACKUP_PROCESS_TIMEOUT_SECONDS` when a large database cannot
complete within one hour.

Each completed snapshot contains a compressed database artifact, configured
media files, and a manifest written last. The manifest records sizes and
SHA-256 checksums, but no database password, file content, or absolute source
path. A prefix without `manifest.json` is incomplete and cannot be restored or
automatically pruned.

Checksums detect corruption; they are not signatures. Restrict write access to
the backup disk, enable provider-side versioning where available, and monitor
storage audit logs so an attacker cannot replace both a manifest and artifacts.

## Create and monitor snapshots

```bash
php artisan capell:backup:create
php artisan capell:backup:create --database-only
php artisan capell:backup:health
php artisan capell:backup:health --json
php artisan capell:backup:prune
php artisan capell:backup:prune --force
```

Health returns a non-zero exit code when storage is unavailable, there is no
completed snapshot, the newest snapshot is stale, retention is insufficient,
or a manifest/artifact size or checksum is wrong. Connect the JSON form to the
normal monitoring system.

Pruning is a dry run unless `--force` is supplied. It keeps the newest
`CAPELL_BACKUP_RETAIN` completed snapshots and refuses unsafe identifiers,
out-of-prefix paths, malformed manifests, and incomplete snapshots.

Schedule creation before the health check, and run pruning separately:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('capell:backup:create')->dailyAt('01:00');
Schedule::command('capell:backup:health --json')->dailyAt('02:00');
Schedule::command('capell:backup:prune --force')->weeklyOn(1, '03:00');
```

## Run a restore drill

Choose a new database name with the configured scratch prefix. When the
snapshot contains media, choose a non-live disk and an empty non-live prefix.

```bash
php artisan capell:backup:restore \
  20260710T010000Z-a1b2c3d4e5f6 \
  capell_restore_20260710 \
  --media-disk=restore-scratch \
  --media-prefix=drills/20260710
```

Before mutation, restore validates the manifest identity and every artifact's
path, size, and checksum. It then restores the database and media into scratch
targets and runs `capell:doctor --json` in a child process against that scratch
database. Passwords remain in process environments for database tools and are
never included in command arguments or output.

The command deliberately cannot restore over a live database or media disk.
If doctor verification fails, scratch data is left in place for diagnosis;
remove it manually after investigating. A successful monthly drill should be
recorded with its snapshot ID, completion time, doctor result, and cleanup.

## Production recovery boundary

During an incident, first preserve the failed environment and verify the
selected snapshot with a scratch restore. Promoting recovered data into
production remains an operator-controlled infrastructure procedure: take the
application offline, stop workers, use the database platform's supported
promotion/import workflow, restore media with the storage provider's tooling,
clear caches, restart workers, and rerun Site Health.

Capell does not automate in-place production recovery because overwriting live
data is a high-impact, platform-specific operation. Keep `.env`, `APP_KEY`,
deployment state, and non-media files in the infrastructure backup plan, and
test that plan alongside this snapshot drill.

## Worked production recovery example

Assume an operator confirms database corruption at 09:42 and the newest healthy
snapshot completed at 09:00.

1. Record the incident time, affected release, database host, newest healthy
   snapshot ID, and recovery lead. Enable maintenance or lockdown and stop
   queue workers so writes do not continue.
2. Preserve the failed database and current media state. Do not overwrite the
   only evidence while trying to recover quickly.
3. Verify backup health, then restore into isolated scratch targets:

   ```bash
   php artisan capell:backup:health --json
   php artisan capell:backup:restore \
     20260712T090000Z-a1b2c3d4e5f6 \
     capell_restore_incident_20260712 \
     --media-disk=restore-scratch \
     --media-prefix=incidents/20260712
   ```

4. Review the doctor result. On the scratch copy, compare critical record
   counts, open representative pages and media, and confirm that the accepted
   recovery point loses no more data than the incident decision allows.
5. Use the database and storage provider's documented promotion/import process
   to replace production from the verified result. This is intentionally
   outside Capell and needs the platform's rollback mechanism.
6. Deploy the matching application release, run migrations only when required,
   then clear caches and restart workers:

   ```bash
   php artisan optimize:clear
   php artisan queue:restart
   php artisan capell:doctor --json
   php artisan capell:backup:health --json
   ```

7. Verify signed-out pages, admin login, publishing, media, redirects, scheduled
   work, and Marketplace connectivity before reopening traffic. Record the
   recovery point, lost-write window, evidence, and cleanup owner.

If verification fails, keep traffic closed and roll back through the
infrastructure provider. Do not improvise an in-place database merge during an
incident unless that procedure was already rehearsed.
