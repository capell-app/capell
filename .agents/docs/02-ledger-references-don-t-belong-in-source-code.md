## Ledger References Don't Belong In Source Code

Do not put internal ledger task IDs (`CAP-XXXX`) in code comments, docblocks,
or class/file names. A ticket reference explains nothing to a future reader
without ledger access, and it rots the moment the ticket is archived or
renumbered — unlike the comment, which lives in the codebase indefinitely.
State the underlying reason directly (the bug, the constraint, the trap being
guarded against); that survives on its own. Ticket IDs belong in commit
messages and PR descriptions, where they're paired with the diff that
resolved them and age gracefully as history rather than as live
documentation.

If removing a `CAP-XXXX:` prefix from an existing comment would leave nothing
of substance behind, the comment was doing no real work either — delete it
rather than just de-prefixing it.
