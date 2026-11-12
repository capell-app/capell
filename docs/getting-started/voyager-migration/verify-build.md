# Verify a migration build from source

**Draft — targeted source checks verified; complete preflight not yet accepted. Admin screenshots not yet captured.**

## What this step achieves

Check the Companion Packages code before using it in a disposable migration.
Use this chapter when the migration build uses source repositories. Keep the
application, Core and Companion Packages revisions with the results.

Released installations can skip this source-contributor chapter. Use the
installation chapter and the selected packages' normal migrations. The commands
here record checks of an unreleased migration build; they are not a requirement
to repeat the entire package test matrix for each site.

Timestamp repairs are package-specific. A fresh schema with safe timestamp
definitions makes the existing repairs no-op after their metadata checks. Keep
those checks; do not add a blanket migration bypass or alter an unrelated host
table's intentional automatic timestamps. Reuse the recorded repair evidence
unless the relevant migrations, resolver or database compatibility changes.

## Before you start

Use the Companion Packages repository as the working directory, its supported
PHP 8.4 dependencies and a disposable SQLite test database. The MariaDB
compatibility check additionally needs a separate disposable local MariaDB
service and its connection environment. Confirm that connection before running
the check; keep it separate from application and customer databases. Keep the previous
source revisions and dependency manifests so the build can be restored.
When regenerating a local Composer overlay, back up its manifest and lock first.
Use the repository generator to select a clean Core source pin. Check the
installed files as well as the lock; a path repository can point at a different
checkout from the one recorded in the configuration.

The complete affected suite also needs clean Core and application source roots.
Point `CAPELL_CORE_REPO_PATH`, `CAPELL_FOUNDATION_ROOT` and
`CAPELL_RELEASE_CORE_ROOT` at Core; point `CAPELL_APPLICATION_ROOT` and
`CAPELL_RELEASE_SKELETON_ROOT` at the application. Set
`CAPELL_RELEASE_ENVIRONMENT=1` for the release fixtures, and provide the
immutable 40-character source commits in `CAPELL_RELEASE_CORE_COMMIT` and
`CAPELL_RELEASE_APP_COMMIT`. Lock admission reads the committed lockfiles; a
working-tree edit is not a replacement for a source pin. Commit and record the
migration build before this check.

Provide an owned MariaDB database whose name starts with
`capell_test_timestamp_`, and set `CAPELL_TIMESTAMP_REPAIR_DATABASE`,
`CAPELL_TIMESTAMP_REPAIR_PORT` and `CAPELL_TIMESTAMP_REPAIR_PASSWORD` explicitly.
Use a disposable local service. Missing inputs stop the runner before it starts
the affected tests. Remove the test database and stop that service afterwards.

## Procedure and exact commands

These commands were run from the Companion Packages repository. Each exited 0:

```sh
node --test tests/JavaScript/added-large-files.test.mjs
php scripts/sync-package-security-manifests.php --check
php scripts/generate-package-readmes.php --check
pre-commit run check-added-large-files
```

The Node run passed four tests. The manifest and documentation checks completed
without reporting drift. The real staged size hook passed on the prepared merge.

### Check the published dependency lock

When a stable release contains the required contracts, verify the public lock
with a shell that has no local Core source override. Back up the previous local
overlay and lock first. These commands exited 0 in the rehearsal:

```sh
COMPOSER=composer.json composer install --no-interaction --no-scripts
COMPOSER=composer.json composer validate --no-check-publish
```

Composer reported `composer.json is valid`. The installation used the five
published foundation packages from the committed lock. Composer scripts were
not run by this installation command; retain the separate application and
migration verification checks before accepting the build.

A clean Git status alone does not establish source provenance. Local Node
dependencies inside a Core package can make a strict committed-tree comparison
fail. Preserve those dependencies and retain the failed check. A public-lock
installation has different evidence from an attested source build; record which
one was tested.

## What you should see

The size tests report four passes and no failures. The hook reports “Passed”.
The security manifest check retains the declared sensitive fields while reading
the logical table names behind prefixes and aliases.

Capell admin screenshots are **not yet captured or accepted** for
this chapter. Capture them from the seeded rehearsal when the installation is
available, at the viewport and theme specified in the chapter list.

## Check it worked

Retain each command's full log and actual exit. A complete source verification
must also cover the naming contracts and the installed-runtime inventories.
Follow [the naming checks](table-naming.md#supporting-package-checks) and the
[installed-runtime lifecycle](../../development/installed-runtime-lifecycle.md).
An interrupted static-analysis worker cannot establish a passing build. A non-empty
JUnit file is also insufficient: nested test fixtures can inherit a parent
report destination and replace its contents. Verify the report belongs to the
expected suite, and isolate nested report destinations before accepting it.

This pilot command also exited 0, with 12 tests and 1,952 assertions:

```sh
vendor/bin/pest packages/contacts/tests/Integration/Providers/InstalledRuntimeParityTest.php packages/layout-builder/tests/Integration/Providers/InstalledRuntimeParityTest.php packages/access-gate/tests/Integration/Providers/InstalledRuntimeParityTest.php --configuration=phpunit.xml
```

The default-schema MariaDB compatibility check was also run successfully:

```sh
php scripts/run-mariadb-migration-check.php
```

It passed one test and 15 assertions after applying, rolling back and reapplying
the complete ordered migration set. The harness creates a generated test database
and removes it in its cleanup block. The disposable service was stopped after
the check. This result does not prove coexistence with Voyager, prefixed schemas
or the host application's integer user keys.

The subsequent run covering all 54 packages with migration metadata passed
110 tests and 32,340 assertions. These tests compare fresh installed, uninstalled
and activated runtime inventories; they do not establish that your destination
site has imported its content correctly.

Review regenerated installed-runtime inventories against an immutable source
commit before accepting them. Check provider order, the package owner and table
roots, source-file hashes, and installation receipt emissions. An inventory
update must explain each behavioural difference; generating a new file does not
prove that the change is correct. Keep the previous inventory and run the parity
tests against the reviewed candidate.

Check that each package requires a released Core version containing the naming
API it imports. A compatible local source checkout can hide an insufficient
Composer minimum. Preserve any higher minimum required by another contract.

## What can go wrong and how to recover

A large-file hook can stall when Python and Git both fill the opposite sides of
a pipe. Use the maintained checker that passes Git a seekable filename list;
retain the size limits and Git LFS handling. Keep the real hook enabled.

If a static store's method declaration disagrees with Core's permit-bearing
contract, complete the dependency pairing. Updating a signature alone leaves
publication and withdrawal behaviour unproved. Verify the whole implementation
and its callers before using the build.

If an analyser crashes on a first-class callable to a Laravel Action, preserve
the normal Action call behind an explicitly typed callback method. Check the
callback's filtering, result order and return type, then verify the page and
static analysis together. A focused clean result resolves that defect; retain
the requirement to finish the complete build gate.

If a security inventory cannot resolve a table, investigate the naming helper.
The scanner must recognise the real Core helper and its configured logical base.
Unknown helpers must remain errors. Do not remove sensitive-field declarations
to make the generated manifest check pass.

A batched check can announce the complete file count and still stop at its first
failing batch. Retain every batch result and a non-zero final exit while reviewing
all remaining drift. For inconsistent tool output, use a new, owned cache with
the same rules; preserve existing caches. Review generated edits before applying
them, including duplicate trait or import suggestions. If a selective invocation
and the complete runner disagree, retain both results and investigate their
configuration, cache and process context. Accept the complete required gate
only after it finishes successfully.

Review automated control-flow changes against real import behaviour. A tool
can incorrectly simplify a condition that distinguishes a first import from an
update. Keep first-import creation checks separate from update and no-op paths,
and rerun the source-key behaviour tests after any such change. During source
verification, those tests caught two failures after an unsafe simplification; the
corrected first-import assertion passed all six tests and the standard
Rector check. Keep the mapping state at the typed assertion boundary so an
incorrect simplification at its caller cannot erase the creation check.

Keep the clock contract when reviewing automated changes. An application can
override Carbon's clock; native persistence clocks must still fence expired
leases and record the actual event time. In the Shop persistence source checks,
three regressions rejected a clock conversion that backdated claims and outbox
events and allowed an expired lease to complete. Retaining the native clock and
limiting the conversion-rule exception to its two owning classes preserved the
existing behaviour.

For a build containing Shop, this command passed 11 tests and 40 assertions:

```sh
vendor/bin/pest packages/shop/tests/Feature/ShopPersistenceTest.php --configuration=phpunit.xml
```

If a test passes alone and fails after another test, inspect shared containers,
model event dispatchers and other process state. Restore the state a fixture
borrows, then rerun the combined checks. Keep the original failure evidence.

If an installed-runtime inventory reports a PHP executable path instead of its
portable placeholder, compare the runner's `PHP_BINARY` with PHP's own resolved
binary path. A symlink and its target can identify the same executable but fail
an exact path replacement. Use the resolved executable consistently, then rerun
the original test. Keep machine paths out of committed inventories. A combined
generator and cache check passed 209 tests and 3,971 assertions after correcting
this runner environment; the inventory itself was unchanged.

A dependency-floor check can also find contracts that exist on Core's main
branch but have no stable release. Record the release dependency and retain the
failed check. Do not lower the declared requirement, remove the imported contract
or substitute a stub to make the build appear releasable.

Published timestamp repairs must remain usable without loading package code.
When adding table prefixes or aliases, test the frozen resolver against Core's
resolver, then run the repair twice against a disposable MariaDB table. Check
that the resolved table loses its implicit timestamp update and an unrelated
host table remains unchanged. The source-level naming and repair contracts
passed 464 tests and 1,793 assertions; the extended MariaDB check passed 133 tests and 1,660 assertions. The
disposable database was removed and its service stopped afterwards.

With the isolated database variables set, these checks passed:

```sh
vendor/bin/pest tests/Packages/Feature/PackageTableNamingTest.php tests/Contracts/PackageTimestampRepairMigrationTest.php tests/Arch/CapellTableNamesTest.php --configuration=phpunit.xml
vendor/bin/pest tests/MariaDB/PackageTimestampRepairTest.php --configuration=phpunit.xml
node --test tests/JavaScript/stdin-reader.test.mjs
```

The Node check passed three tests. Its child processes receive delayed stdin
chunks split inside a UTF-8 character; each helper must consume the complete
stream before parsing it. A synchronous read can fail with `EAGAIN` when a
process runner supplies a non-blocking pipe.

A nested test runner can inherit the outer runner's JUnit destinations and
replace its reports. Give nested fixtures their own report destinations, or
remove the inherited values, and check that the outer reports remain unchanged.
A report written by a one-test fixture is not evidence that the outer shard
completed. Retain incomplete shard output and memory-limit errors as failures.
The complete preflight has **not yet passed**; focused passing checks do not
replace it.

For runtime contribution failures, inspect provider activation as well as the
provider list. A Public test harness can load its selected providers while an
incompatible lifecycle role leaves them pending. Also compare registry
resolution order: deferred callbacks from another package can be attributed to
the package that resolves the registry. Record a framework gap and retain the
complete audit. Warming a registry before the test can hide that failure.

An undeclared frontend component needs its registered key in the manifest.
Verify both that the runtime receipt exists and that the auditor accepts the
declaration. The focused regression failed before the declaration was corrected
and passed afterwards; unrelated audit failures remain recorded separately.

## Roll back

Restore the previous source revisions and dependency manifests together, then
verify that the restored build uses those files. This restore procedure is
**not yet verified** in the rehearsal. Keep application backups until it is.

## Migration chapter list

Return to [the migration chapter list](index.md), then audit the source site.
