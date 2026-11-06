# Reconcile content and media

**Draft — not yet verified end to end.**

## What this step achieves

Account for every source record, including deliberate exclusions.

## Before you start

Expected counts fixed before import, source-key reports, destination queries and an original-file inventory. Record counts for each selected scope rather than combining unrelated sessions.

## Procedure and exact commands

Separate pages, articles, custom templates, widget placements, navigation by menu, settings by disposition, roles and permission mappings, and media by original path. Explain exclusions such as the Voyager admin menu. Keep custom application data out of CMS totals.

The executable command transcript for this phase is **not yet verified**. Do not
copy a command from another release or infer flags from this draft. Add the exact
command, working directory and exit only after running it on disposable data.

## What you should see

Voyager: seeded source counts. Capell: matching destination lists and reconciliation report. Alt text should distinguish imported rows from deliberately excluded rows.

Screenshots are **not yet captured or accepted**. Do not substitute screenshots
from a live site or an unrelated workflow.

## Check it worked

Require source total = imported + deliberately excluded + unresolved, with unresolved zero. Check placement foreign keys through source keys and crawl every rendered /storage/ reference. Not yet verified.

The verification command and expected output are **not yet verified**.

## What can go wrong and how to recover

Do not repair a mismatch by changing the expected count after import. Find duplicate source keys, unsupported mappings, scope selection or missing files; repair the mapping and rerun.

## Roll back

Retain the failed reconciliation with its session IDs. Roll back affected sessions or restore the rehearsal destination, then run again from the same immutable source.

## Next

Return to the [migration chapter list](index.md).
