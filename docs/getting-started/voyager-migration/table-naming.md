# Choose table prefixes and aliases

**Draft — not yet verified end to end.**

## What this step achieves

Keep CMS tables distinct from existing application tables.

## Before you start

The collision inventory, package migration list and host users primary-key type. Make the naming decision before the first destination migration.

## Procedure and exact commands

Use capell.database.table_prefix with an empty default and capell.database.table_aliases for individual names. An alias takes precedence over the prefix. External packages use their own table configuration. Confirm that foreign-table adoption is refused and that user foreign keys match the host key type.

The executable command transcript for this phase is **not yet verified**. Do not
copy a command from another release or infer flags from this draft. Add the exact
command, working directory and exit only after running it on disposable data.

## What you should see

Voyager: source content table represented in BREAD. Capell: collision choice and resulting installation state. Alt text should explain the retained source and separate destination names.

Screenshots are **not yet captured or accepted**. Do not substitute screenshots
from a live site or an unrelated workflow.

## Check it worked

Check default-empty, prefix-only, alias-over-prefix and collision refusal. Verify migrations, models, queries, foreign keys and validation agree on the same physical names. Not yet verified.

Include explicit index names, morph indexes and foreign-key symbols in that
check. A table can have the correct alias and still fail installation because
its index name collides with a legacy table. Leave a legacy table and its indexes
in the fixture, configure a different package table name, then alias that name.
Check that the new indexes are created and the legacy table is unchanged.

Collision planning must work before a package is installed or enabled. Check
the plan for an uninstalled package with both a configured table name and a
Core alias. A registration available only after runtime activation is too late
for an installation preflight. Also verify that a connection-specific package
reads its ownership evidence from that connection's migration ledger.

The verification command and expected output are **not yet verified**.

## What can go wrong and how to recover

An existing table is not evidence of Capell ownership. Stop if a migration silently adopts it. Do not change naming after tables have been populated without a separately reviewed migration.

A matching migration filename is not sufficient ownership evidence either:
an application can have created its own table with the same conventional name.
Retain that case in the coexistence fixture and stop if it is silently adopted.

## Roll back

Before any content import, restore the destination backup and naming configuration together. Changing config alone does not rename populated tables.

## Next

Return to the [migration chapter list](index.md).
