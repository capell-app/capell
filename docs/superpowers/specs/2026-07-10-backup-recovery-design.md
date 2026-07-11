# Backup and Recovery Design

## Purpose

Capell needs a customer-operable backup capability, not only deployment hooks
and a prose runbook. The feature must create verifiable database and media
snapshots, report retention/freshness, and prove restores without making the
live database or media disk the default target.

## Product Surface

Core provides four commands:

- `capell:backup:create` creates a database snapshot and, when configured,
  streams media into the same snapshot.
- `capell:backup:health` verifies freshness, retained snapshot count,
  manifests, artifacts, sizes and SHA-256 checksums.
- `capell:backup:restore` restores one named snapshot only into an isolated
  scratch database and optional scratch media disk/prefix, then runs
  `capell:doctor` against the restored database.
- `capell:backup:prune` removes snapshots beyond configured retention only
  after validating their manifests and only beneath Capell's configured backup
  prefix.

Commands are thin. Actions own orchestration and return typed Data objects.

## Storage and Manifest

The operator configures a Laravel backup disk that is distinct from live media
storage. Every snapshot lives at:

```text
{prefix}/{UTC timestamp + random suffix}/
  manifest.json
  database.sql.gz | database.sqlite.gz
  media/{disk}/{original path}
```

The manifest records format version, snapshot id, UTC creation time, database
driver/artifact metadata, media artifact metadata, source disk labels, file
counts, byte counts and SHA-256 checksums. It contains no passwords, access
keys, connection URLs, absolute application paths or customer content.

Database and media content is streamed through bounded local temporary files;
whole backups are never held in memory. A manifest is written last, so an
interrupted snapshot is never considered healthy.

## Database Drivers

Core supports SQLite, MySQL/MariaDB and PostgreSQL through a small driver
contract:

- SQLite copies the configured database file.
- MySQL/MariaDB uses argument-array `mysqldump`/`mysql` processes and passes the
  password only through `MYSQL_PWD`.
- PostgreSQL uses argument-array `pg_dump`/`psql` processes and passes the
  password only through `PGPASSWORD`.

Binary names are configurable. Commands and exceptions must never print
credential environment values. Unsupported drivers fail before creating a
snapshot directory.

## Restore Safety

Restore accepts a snapshot id, never an arbitrary path. It validates the
manifest and database checksum before any mutation. The target database must:

- match the configured scratch prefix;
- contain only safe identifier characters (or be a scratch SQLite path);
- differ from the live database;
- be explicitly supplied by the operator.

Media restore is optional and requires a target disk different from every live
media disk plus a non-empty scratch prefix. Existing target files are rejected
unless the target prefix is empty. Live in-place restore is outside this
command and remains a deliberate incident-runbook operation.

## Health and Retention

Health fails when backup is disabled/unconfigured, no complete manifest exists,
the newest snapshot is stale, retained count is below policy, a manifest is
invalid, or any referenced artifact is missing, incorrectly sized or has a
checksum mismatch. JSON output supports external monitoring.

Pruning defaults to dry-run. Mutation requires `--force`; it keeps the newest
configured count and refuses paths outside the configured prefix.

## Verification

- Direct Action tests cover traversal rejection, unsupported drivers,
  incomplete snapshots, checksum mismatch, stale/insufficient retention,
  media target isolation and prune boundaries.
- Driver tests cover SQLite round-trip and secret-free MySQL/PostgreSQL process
  construction.
- Command tests prove delegation, JSON output, dry-run defaults and non-zero
  failure exits.
- A scratch SQLite round-trip runs `capell:doctor` against restored state.
- Focused Core tests, Pint, PHPStan and the repository preflight gate complete
  the slice.
