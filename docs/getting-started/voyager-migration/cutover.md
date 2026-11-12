# Cut over within a rollback window

**Draft — not yet verified end to end.**

## What this step achieves

Give the site owner an executable release decision with a clear stop point.

## Before you start

Two successful timed rehearsals, approved freeze and communications, recent full database and storage backups, disk capacity, exact release artifacts and an operator authorised to deploy.

## Procedure and exact commands

The owner freezes orders and content, makes the final backup, applies the rehearsed release/migration/import sequence, rebuilds caches, checks reconciliation and smoke journeys, then decides whether to reopen. Record the first post-switch write. Never run a draft procedure on production.

The executable command transcript for this phase is **not yet verified**. Do not
copy a command from another release or infer flags from this draft. Add the exact
command, working directory and exit only after running it on disposable data.

## What you should see

Capell: disposable rehearsal after smoke checks. Production screenshots are not required and must not expose customers.

Screenshots are **not yet captured or accepted**. Do not substitute screenshots
from a live site or an unrelated workflow.

## Check it worked

Verify the customer, administrator and order-processing journeys, sandbox payment, expected redirects and cache behaviour. Confirm remember tokens and personal access tokens survive while old sessions are cleared. Not yet verified.

The verification command and expected output are **not yet verified**.

## What can go wrong and how to recover

Stop before reopening if any acceptance check fails. After the first post-go-live business write, a database restore would discard new business data: use an agreed roll-forward procedure instead.

## Roll back

The restore window ends before the first post-go-live business write. Within that window restore the database and files and switch the release back, then verify the legacy journeys. The owner records the decision and timestamp.

## Migration chapter list

Return to the [migration chapter list](index.md).
