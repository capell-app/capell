# Measure the rehearsal and freeze

**Draft — not yet verified end to end.**

## What this step achieves

Set a downtime budget from repeated evidence rather than an estimate.

## Before you start

Two disposable rehearsal destinations, immutable authorised source fixtures, a full backup/restore route and a timing recorder. Keep external payments and fulfilment in sandbox mode.

## Procedure and exact commands

Run the entire operation twice from fresh starting states. Within each rehearsal repeat the import unchanged to prove no-op behaviour. Time freeze, final copy, migrations, import, cache rebuild, reconciliation and smoke checks separately. Test session logout and token preservation.

The executable command transcript for this phase is **not yet verified**. Do not
copy a command from another release or infer flags from this draft. Add the exact
command, working directory and exit only after running it on disposable data.

## What you should see

Capell: reconciliation and role smoke results before reopening. Alt text should identify the completed checkpoint.

Screenshots are **not yet captured or accepted**. Do not substitute screenshots
from a live site or an unrelated workflow.

## Check it worked

Record elapsed time and actual exit for every stage, verify restore time and set the freeze budget from the slower successful rehearsal plus agreed contingency. Not yet verified.

The verification command and expected output are **not yet verified**.

## What can go wrong and how to recover

Do not publish a downtime promise from a partial run. A failed phase invalidates the rehearsal timing until the cause is fixed and the full sequence completes.

## Roll back

Restore database and files and switch back the release in the rehearsal. Verify the legacy site works after restoration before accepting the rollback procedure.

## Next

Return to the [migration chapter list](index.md).
