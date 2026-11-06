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

The verification command and expected output are **not yet verified**.

## What can go wrong and how to recover

An existing table is not evidence of Capell ownership. Stop if a migration silently adopts it. Do not change naming after tables have been populated without a separately reviewed migration.

## Roll back

Before any content import, restore the destination backup and naming configuration together. Changing config alone does not rename populated tables.

## Next

Return to the [migration chapter list](index.md).
