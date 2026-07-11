# Backup and Recovery Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship customer-operable, verifiable database/media backups with scratch-only restore, retention health and doctor verification in Capell Core.

**Architecture:** Thin Artisan commands delegate to typed Actions. A tagged database-driver contract supports SQLite, MySQL/MariaDB and PostgreSQL; a backup filesystem service streams artifacts and manifests through a configured Laravel disk. Restore validates identity, checksum and scratch isolation before invoking a driver or `capell:doctor`.

**Tech Stack:** PHP 8.4, Laravel Filesystem/Database, Symfony Process, Spatie Laravel Data, Pest.

---

### Task 1: Configuration, Data and driver boundary

**Files:**
- Create: `packages/core/config/backup.php`
- Create: `packages/core/src/Contracts/Backup/DatabaseBackupDriver.php`
- Create: `packages/core/src/Data/Backup/BackupArtifactData.php`
- Create: `packages/core/src/Data/Backup/BackupManifestData.php`
- Create: `packages/core/src/Data/Backup/BackupHealthReportData.php`
- Create: `packages/core/src/Support/Backup/DatabaseBackupDriverRegistry.php`
- Modify: `packages/core/src/Providers/CapellServiceProvider.php`
- Test: `packages/core/tests/Unit/Backup/DatabaseBackupDriverRegistryTest.php`

- [x] Add disabled-by-default disk/prefix, connection, media disks, freshness,
  retention, scratch-prefix/directory and binary configuration.
- [x] Define typed manifest/artifact/health boundaries that serialize without
  credentials, absolute paths or content.
- [x] Register built-in drivers through a registry and fail clearly for an
  unsupported connection driver.
- [x] Run the focused registry/Data tests and commit.

### Task 2: Database snapshot drivers

**Files:**
- Create: `packages/core/src/Support/Backup/Drivers/SqliteDatabaseBackupDriver.php`
- Create: `packages/core/src/Support/Backup/Drivers/MySqlDatabaseBackupDriver.php`
- Create: `packages/core/src/Support/Backup/Drivers/PostgresDatabaseBackupDriver.php`
- Create: `packages/core/src/Support/Backup/BackupTemporaryFiles.php`
- Test: `packages/core/tests/Unit/Backup/DatabaseBackupDriversTest.php`

- [x] Implement SQLite file copy and isolated restore under the configured
  scratch directory.
- [x] Implement MySQL/MariaDB dump/create/restore with argument-array processes
  and `MYSQL_PWD` only in the process environment.
- [x] Implement PostgreSQL dump/create/restore with argument-array processes
  and `PGPASSWORD` only in the process environment.
- [x] Prove failed processes report the operation without printing secrets;
  run focused driver tests and commit.

### Task 3: Snapshot creation and media streaming

**Files:**
- Create: `packages/core/src/Actions/Backup/CreateBackupAction.php`
- Create: `packages/core/src/Support/Backup/BackupArtifactStore.php`
- Create: `packages/core/src/Console/Commands/CreateBackupCommand.php`
- Test: `packages/core/tests/Feature/Backup/CreateBackupActionTest.php`
- Test: `packages/core/tests/Feature/Console/CreateBackupCommandTest.php`

- [x] Stream the database dump into gzip, calculate SHA-256/size, and upload it
  beneath a generated safe snapshot id.
- [x] Stream configured media one file at a time through bounded temporary
  files, recording source disk/path, stored path, size and checksum.
- [x] Write `manifest.json` last and clean incomplete snapshot prefixes on
  failure.
- [x] Make the command reject disabled/unconfigured backup, support
  `--database-only`, and print only snapshot metadata.
- [x] Run creation/command tests and commit.

### Task 4: Health and retention

**Files:**
- Create: `packages/core/src/Actions/Backup/InspectBackupHealthAction.php`
- Create: `packages/core/src/Actions/Backup/PruneBackupsAction.php`
- Create: `packages/core/src/Console/Commands/BackupHealthCommand.php`
- Create: `packages/core/src/Console/Commands/PruneBackupsCommand.php`
- Test: `packages/core/tests/Feature/Backup/BackupHealthAndRetentionTest.php`

- [x] Fail health for disabled/unconfigured storage, no manifests, stale newest
  snapshot, insufficient retention, invalid manifests, missing artifacts,
  incorrect sizes or checksum mismatches.
- [x] Support human and JSON health output with non-zero degraded exits.
- [x] Keep newest configured snapshots; make prune dry-run by default and
  require `--force` for deletion beneath the configured prefix.
- [x] Prove traversal/out-of-prefix deletion is impossible; run focused tests
  and commit.

### Task 5: Scratch-only restore and doctor verification

**Files:**
- Create: `packages/core/src/Actions/Backup/RestoreBackupAction.php`
- Create: `packages/core/src/Console/Commands/RestoreBackupCommand.php`
- Test: `packages/core/tests/Feature/Backup/RestoreBackupActionTest.php`
- Test: `packages/core/tests/Feature/Console/RestoreBackupCommandTest.php`

- [x] Accept only a manifest-backed snapshot id and validate every database
  artifact checksum before restore.
- [x] Require an explicit safe scratch database name that differs from live;
  reject in-place restore and unsafe identifiers.
- [x] Require media restore to a non-live disk and non-empty scratch prefix;
  reject a non-empty target prefix.
- [x] Run `capell:doctor --json` in a child process with only the scratch
  database override and fail when doctor fails.
- [x] Prove an SQLite create/restore round trip and all rejection paths; commit.

### Task 6: Documentation, integration and advanced review

**Files:**
- Modify: `packages/core/src/Providers/CapellServiceProvider.php`
- Modify: `docs/operations/backups.md`
- Modify: `docs/operations/index.md`
- Modify: `docs/development/artisan-commands.md`
- Create: `packages/core/tests/Feature/Backup/BackupSurfaceRegistrationTest.php`

- [x] Register/configure all four commands and document configuration,
  scheduling, scratch drill, health monitoring, retention and deliberate
  in-place recovery boundary.
- [x] Add command/config discovery contracts and run all focused Core backup
  tests, Pint, PHPStan and changed-file preflight.
- [x] Run an independent-equivalent spec, security/data-loss and operator-flow
  review locally; resolve all P1/P2 findings.
- [x] Run the broad Core release gate, commit evidence, rebase onto current
  `4.x`, integrate when its mainline worktree is safe, and update the app
  commercial-readiness tracker.

## Completion evidence

- Focused backup and command coverage passes: 25 tests, 135 assertions.
- Existing doctor command regression coverage passes: 25 tests, 77 assertions.
- Scoped PHPStan reports no backup-source errors. The command still exits on
  ten repository-wide unmatched-ignore rules; full preflight separately stops
  on 42 pre-existing Admin typing errors outside this diff.
- The broad Core gate ran 1,680 tests. Its only failure was the architecture
  ban on `tempnam`; temporary files now use random exclusive creation, the
  complete focused suite passes again, and the exact architecture file passes
  with 5 tests and 46 assertions.
- `composer audit --locked` reports no dependency vulnerability advisories.
- Local spec, security/data-loss, and operator-flow review fixed cached-config
  doctor targeting, long-running process timeouts, future-dated manifests,
  unsafe/occupied media targets, path normalization, and temporary-file
  creation. No unresolved P1/P2 finding remains in the reviewed slice.
