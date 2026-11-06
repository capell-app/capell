# Verify a migration build from source

**Draft — source checks verified; admin screenshots not yet captured.**

## What this step achieves

Check the Companion Packages code before using it in a disposable migration.
Use this chapter when the migration build uses source repositories. Keep the
application, Core and Companion Packages revisions with the results.

## Before you start

Use the Companion Packages repository as the working directory, its supported
PHP 8.4 dependencies and a disposable SQLite test database. Keep the previous
source revisions and dependency manifests so the build can be restored.
When regenerating a local Composer overlay, back up its manifest and lock first.
Use the repository generator to select a clean Core source pin. Check the
installed files as well as the lock; a path repository can point at a different
checkout from the one recorded in the configuration.

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

## What you should see

The size tests report four passes and no failures. The hook reports “Passed”.
The security manifest check retains the declared sensitive fields while reading
the logical table names behind prefixes and aliases.

Voyager and Capell admin screenshots are **not yet captured or accepted** for
this chapter. Capture them from the seeded rehearsal when the installation is
available, at the viewport and theme specified in the chapter list.

## Check it worked

Retain each command's full log and actual exit. A complete source verification
must also cover the naming contracts and the installed-runtime inventories.
Follow [the naming checks](table-naming.md#supporting-package-checks) and the
[installed-runtime lifecycle](../../development/installed-runtime-lifecycle.md).
An interrupted static-analysis worker cannot establish a passing build.

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

If a security inventory cannot resolve a table, investigate the naming helper.
The scanner must recognise the real Core helper and its configured logical base.
Unknown helpers must remain errors. Do not remove sensitive-field declarations
to make the generated manifest check pass.

If a test passes alone and fails after another test, inspect shared containers,
model event dispatchers and other process state. Restore the state a fixture
borrows, then rerun the combined checks. Keep the original failure evidence.

A dependency-floor check can also find contracts that exist on Core's main
branch but have no stable release. Record the release dependency and retain the
failed check. Do not lower the declared requirement, remove the imported contract
or substitute a stub to make the build appear releasable.

## Roll back

Restore the previous source revisions and dependency manifests together, then
verify that the restored build uses those files. This restore procedure is
**not yet verified** in the rehearsal. Keep application backups until it is.

## Next

Return to [the migration chapter list](index.md), then audit the source site.
