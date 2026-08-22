# CI And Test Shards

Capell's CI runs code-quality checks and divides the test suite with Pest's native sharding support.

## Pest Shards

`composer test:preflight` runs Pest's parallel suite. Pull-request jobs set `PEST_SHARD` and use `composer test:fast:ci` to select their native Pest shard.

Run `composer test:shards` when the timing manifest needs refreshing. Pest writes `tests/.pest/shards.json` directly.

Pest 4.7 requires a small sharding compatibility patch for this monorepo. It supports package namespaces, preserves the discovery process memory limit, and prevents parallel-worker PHP options from leaking into PHPUnit test discovery. Composer applies it after autoload refreshes, and `composer check:pest-shards` fails on unsupported Pest versions so the patch is reviewed and removed once upstream behavior is sufficient.

## Composer Refresh For Screenshot Fixtures

Composer install and autoload refresh matter for screenshot and docs checks because generated Filament/admin fixtures depend on package discovery and Testbench state. CI runs Composer validation and dependency install before quality checks so package providers, screenshot fixtures, and generated docs state use the current lock file rather than stale vendor metadata.

## Local Checks

Use the narrowest command while changing code:

```bash
vendor/bin/pest packages/frontend/tests/Unit/Cache --configuration=phpunit.xml
```

Before a finished branch, use:

```bash
composer preflight:all
```

`preflight:all` applies repository-wide Rector transformations and Pint formatting automatically, then runs Prettier in check mode. It also runs documentation checks, the root-doc guard, PHPStan baseline growth protection, and Pest. Review and commit any generated changes before pushing; CI asserts that the command leaves a clean checkout, so uncommitted transformations still fail the build.

Preflight runs every selected gate and prints a per-stage summary at the end, even when an earlier independent gate fails. Pest also runs without fail-fast flags so one integrated run reports the complete test failure set. To rerun only named failing gates, pass their stage names after `--`:

```bash
composer preflight -- phpstan tests
composer preflight:all -- docs-links phpstan-baseline
```

An unknown stage exits with the available names. The final preflight exit remains non-zero when any selected gate fails.

To apply Rector, Pint, and Prettier changes before rerunning the same checks, use:

```bash
composer preflight:fix
```

## Database Portability Matrix

Test All owns a focused portability group for every advertised database family:

| Cell                            | Runtime                                          |
| ------------------------------- | ------------------------------------------------ |
| `l13-portability-sqlite`        | SQLite from PHP 8.4                              |
| `l13-portability-mysql-8`       | `mysql:8.0`                                      |
| `l13-portability-mariadb-10-5`  | `mariadb:10.5` through the MariaDB platform seam |
| `l13-portability-postgresql-16` | `postgres:16` through `pdo_pgsql`                |

Every cell runs the same repository-owned Pest group. It proves the complete Core
migration set, database provisioning, install, doctor and upgrade paths, query and
JSON dialects, uniqueness and foreign-key behaviour, and safe backup/restore
process contracts. MariaDB is a separate service and platform assertion rather
than an alias for the MySQL cell. PostgreSQL-specific executable assertions run in
the PostgreSQL cell.

Run one exact committed-head cell locally with:

```bash
composer test:all:matrix:local -- --cell=l13-portability-postgresql-16
```

The local runner creates a detached worktree at the current `HEAD`, prepares the
pinned Laravel/Testbench dependencies, and owns a generated database name, random
host port, and disposable service container. It never uses or mutates a shared
development database. Evidence is written beneath `.test-all-results/`; a failed
or timed-out cell is incomplete evidence, not support proof.

## Next

- [Development commands](commands.md)
- [Docs ownership rules](docs-ownership.md)
