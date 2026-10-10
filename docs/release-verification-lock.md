# Release verification gate

Broad Composer tests and preflights take a shared lease on the host release
verification gate. Full release verification takes its exclusive lease. An
ordinary broad command started during a release prints a waiting message and
runs after the release finishes; the lease remains held while its command runs.
A release child can proceed only with the current live owner's token.

Use the checkout's own `./capell` Composer and test entrypoints for Docker verification. Its Python 3
bridge holds the host lease for the Compose invocation and disables the duplicate
container lease for that command. A container temporary directory cannot see the
host's gate. Calling Composer directly inside a container coordinates only that
container's filesystem and does not provide host-wide exclusion. The bridge
fails before dispatch if Python 3 or its lock file is unavailable.

Focused single-file wrapper tests and direct native single-file Pest runs remain
unaffected. Named PHP stage locks also keep their existing behaviour. To make an
explicit diagnostic exception for a broad command, set
`CAPELL_NO_RELEASE_LOCK=1` for that invocation. `CAPELL_NO_LOCK=1` bypasses named
stage locks but does not bypass the release gate. The App pre-merge runner's
`--ignore-release-lock` option applies the same explicit exception to its children.

The default gate is `capell-release-verification.lock` in the host temporary
directory. `CAPELL_RELEASE_VERIFICATION_LOCK_PATH` selects an absolute path;
use the same path in the verifier and its ordinary command invocations.
Do not delete a live gate file: replacing its inode would defeat exclusion.
Owner tokens and lock paths describe scheduling and do not change immutable
lane evidence keys.
