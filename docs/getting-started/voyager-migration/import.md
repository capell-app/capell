# Preview, import and repeat

**Draft — not yet verified end to end.**

## What this step achieves

Move supported content and prove an unchanged source produces a no-op.

## Before you start

Approved mappings, installed optional dependencies, destination layouts/blueprints, role mappings, locale mappings and accessible original media. Back up the destination and freeze the rehearsal source.

## Procedure and exact commands

Run inventory and preview first. Resolve unsupported mappings and URL conflicts before execution. Execute global and site-owned entities in the dependency order the installed command supports. Record each session separately. Repeat the exact selections, source label, site and mappings.

The executable command transcript for this phase is **not yet verified**. Do not
copy a command from another release or infer flags from this draft. Add the exact
command, working directory and exit only after running it on disposable data.

## What you should see

Capell: completed session report followed by the unchanged rerun report. Alt text must call out zero created and updated rows on the rerun.

Screenshots are **not yet captured or accepted**. Do not substitute screenshots
from a live site or an unrelated workflow.

## Check it worked

For each entity retain created, updated, skipped and failed totals. The unchanged rerun must have zero created, updated and failed rows. Test a destination-edited record and verify it is reported rather than overwritten. Not yet verified.

The verification command and expected output are **not yet verified**.

## What can go wrong and how to recover

A partial session is not a clean retry. Read its failure and provenance before resuming. Do not enable force flags merely to bypass conflicts; prove their meaning against the installed version.

## Roll back

Roll back site sessions before their prerequisite global sessions. Verify updates restore before-images and unrelated destination rows survive. Restore backup if effects fall outside recorded provenance.

## Next

Return to the [migration chapter list](index.md).
