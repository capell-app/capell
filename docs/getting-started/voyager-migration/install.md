# Install Capell beside Voyager

**Draft — not yet verified end to end.**

## What this step achieves

Prepare the replacement CMS while keeping application-owned tables and behaviour intact.

## Before you start

A pinned compatible Laravel/PHP release, a source backup, storage snapshot, destination backup, tested restore and the required package entitlements. Upgrade the framework separately from changing content rendering.

## Procedure and exact commands

Keep Voyager source tables available to the importer without loading Voyager PHP classes on the new framework. Install the receiving site, layouts and blueprints, then the optional importer packages and their declared dependencies. Keep custom checkout and account code on its existing tables.

The executable command transcript for this phase is **not yet verified**. Do not
copy a command from another release or infer flags from this draft. Add the exact
command, working directory and exit only after running it on disposable data.

## What you should see

Voyager: seeded source dashboard. Capell: installed site dashboard with the chosen site visible. Neither screenshot may include customer activity.

Screenshots are **not yet captured or accepted**. Do not substitute screenshots
from a live site or an unrelated workflow.

## Check it worked

Prove installation against both a fresh schema and a schema containing the legacy table names. Compare source schema and data before and after. Not yet verified.

The verification command and expected output are **not yet verified**.

## What can go wrong and how to recover

Stop on an unresolved table collision, unsupported host user key type or missing entitlement. Do not rename legacy tables to get past an installer failure.

## Roll back

Restore the destination database and release together. Keep the source and its files unchanged; do not remove source tables as part of installation.

## Next

Return to the [migration chapter list](index.md).
