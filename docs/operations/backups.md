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
CAPELL_BACKUP_SIGNING_KEY_ID=primary
CAPELL_BACKUP_SIGNING_SECRET_KEY=<base64-ed25519-secret-key>
CAPELL_BACKUP_SIGNING_PUBLIC_KEY=<base64-ed25519-public-key>
CAPELL_BACKUP_MAX_AGE_HOURS=26
CAPELL_BACKUP_MINIMUM_RETAINED=7
CAPELL_BACKUP_RETAIN=30
CAPELL_BACKUP_PRUNE_SCHEDULE_ENABLED=false
CAPELL_BACKUP_PRUNE_SCHEDULE_CRON="0 3 * * 1"
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

Every new manifest carries an Ed25519 signature covering its identity, database
connection and driver, timestamp, and all database/media paths, sizes and SHA-256
digests. The compressed dump is authenticated through its signed digest and is
checked again after download, before decompression or execution. Signing uses the
same canonical JSON and Ed25519 verification as project-build manifests.

Generate a dedicated keypair once on a trusted host with PHP Sodium:

```php
$keypair = sodium_crypto_sign_keypair();
$secretKey = base64_encode(sodium_crypto_sign_secretkey($keypair));
$publicKey = base64_encode(sodium_crypto_sign_publickey($keypair));
```

Store these values in your secret manager and supply the environment variables
above. Keep the secret key outside the backup disk and retain it securely with
your infrastructure recovery material. Creation refuses missing, malformed, or
mismatched signing keys and removes the incomplete snapshot. Never log the secret
key or commit it. A restore-only host needs only trusted public keys.

`backup.signing.public_keys` maps key IDs to trusted base64 public keys. When
rotating, add the new public key to that map, select its ID and matching secret
for creation, and retain old public keys for as long as their snapshots must
restore. Restore selects a key from this configured map, never from backup
storage. Removing a key revokes restoration of its snapshots.

The format-version-1 data shape is retained with an added `signature` object
(`algorithm`, `keyId`, `value`). Unsigned legacy snapshots and snapshots with
unknown keys or altered contents are refused for restore. Health reports them
as invalid, and automatic pruning leaves them untouched. There is no unsigned
restore bypass or automatic signing of old storage contents: create fresh signed
snapshots from trusted live data before relying on the new restore path. Keep
legacy recovery isolated and independently authenticated outside this command.

Restrict write access to the backup disk, enable provider-side versioning where
available, and monitor storage audit logs. Signatures authenticate content;
retention, freshness monitoring and infrastructure backups remain necessary.

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

Capell can schedule forced pruning when
`CAPELL_BACKUP_PRUNE_SCHEDULE_ENABLED=true`. It is disabled by default because
pruning deletes completed snapshots. The default cron runs at 03:00 every
Monday; override it with `CAPELL_BACKUP_PRUNE_SCHEDULE_CRON`. Scheduled pruning
uses Laravel's overlap and single-server guards.

Schedule creation before the health check. If Capell's built-in pruning
schedule remains disabled, schedule pruning separately:

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

Before mutation, restore verifies the manifest signature against configured
trusted keys, then validates its identity and every artifact's path, size, and
checksum. It then restores the database and media into scratch
targets and runs `capell:doctor --json` in a child process against that scratch
database. Passwords remain in process environments for database tools and are
never included in command arguments or output.

Single-disk snapshots retain the existing `prefix/source-path` restore layout.
Multi-disk snapshots restore to `prefix/source-disk/source-path`, with each disk
name URL-encoded as one directory segment. Destination collisions (including
case-only, equivalent Unicode spellings, and file/directory conflicts) and unsafe
paths are rejected before database or media mutation. A scratch prefix beneath
an existing file is also rejected before the database restore starts.
Completed transfer temporary files are released as
soon as their write and checksum work finishes, so media scratch usage is bounded
by the active artifact rather than the entire collection.

The command deliberately cannot restore over a live database or media disk.
If media restore or doctor verification fails, scratch data is left in place for
diagnosis and the command exits non-zero. It names the scratch database, the
media disk and prefix, and how many media files were written before the failure.
A failed doctor run lists each failed check with its remedy; a doctor process
that could not run and output that was not valid JSON are reported as distinct
failures, with credentials redacted. No live database or media is changed. Remove
the scratch targets manually after investigating, then retry with a new scratch
database name and an empty media prefix. A successful monthly drill should be
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
