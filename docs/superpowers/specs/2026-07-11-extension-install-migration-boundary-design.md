# Extension Install Migration Boundary

## Problem

`InstallPackageAction` currently enters its install/failure boundary only when a package declares an install command or Action. Manifest-v3 packages that declare schema or settings migrations but no install lifecycle can therefore be marked installed without publishing or running those migrations.

## Design

`InstallPackageAction` becomes the single manifest-driven install boundary:

1. Resolve a `NullProgressReporter` when no reporter is supplied.
2. Validate bundle members and package requirements as today.
3. Mark every package `installing` before provider, migration, or lifecycle work.
4. Register install and console providers.
5. Read the manifest `database.migrations` and `database.settings` flags through typed `PackageData` predicates.
6. Strictly publish only the declared package directories. A declared directory that is missing or contains no migration files fails installation.
7. Run schema-only, settings-only, or combined migrations through `RunMigrationsAction` using the corresponding application migration paths.
8. Run the existing explicit install lifecycle, when present.
9. Mark the package installed, boot its installed providers, clear caches, and dispatch existing notifications/events.

Publishing, migration, provider, or lifecycle exceptions are handled by one catch that records the package as failed and rethrows. The existing bundle-member rollback remains unchanged.

## Compatibility

- `PublishPackageMigrationsAction` gains opt-in strict declared-file validation; its existing permissive defaults remain unchanged for legacy lifecycle Actions.
- `RunMigrationsAction` gains schema selection while retaining its existing default combined behavior and existing schema-only call semantics.
- Explicit lifecycle Actions still run once and retain responsibility for non-migration setup. Their legacy idempotent migration calls may remain during transition.
- Installed-provider boot stays after `markPackageInstalled()` because Capell providers gate their boot callbacks on installed state.

## Verification

Focused tests cover:

- A no-lifecycle package declaring both schema and settings migrations.
- Missing/empty declared migrations and migration command failure.
- A no-database package, including undeclared directories that must not be published.
- Migration completion and `installing` state before an explicit lifecycle Action.
- Schema-only and settings-only migration path selection.
- Existing bundle rollback and lifecycle behavior through the current integration suite.

Structural review confirms the affected no-lifecycle packages are represented by this contract: `capell-app/agent-bridge`, `capell-app/content-sections`, `capell-app/deployments`, and `capell-app/navigation`.
