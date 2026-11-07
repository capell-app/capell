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

Capell: collision choice and resulting installation state. Alt text should explain the retained source and separate destination names.

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

### Supporting package checks

The following command was run from the Companion Packages repository with its
PHP 8.4 dependencies, an SQLite in-memory test database and the matching Core
checkout. It exited **0: 73 tests, 454 assertions**. These checks exercise naming,
collision metadata, configured bases, aliases, index coexistence and the
connection-specific migration ledger. They do not prove installation or import
against your site's database.

```sh
vendor/bin/pest tests/Arch/CapellTableNamesTest.php tests/Packages/Feature/PackageTableNamingTest.php tests/Packages/Feature/CapellModelTablePrefixTest.php tests/Packages/Feature/PackageMigrationMetadataTest.php tests/Unit/PhpstanPackageMigrationSchemaTest.php --configuration=phpunit.xml
```

Record the exact Core and Companion Packages revisions with your result. A
successful focused test run does not replace the integration checks for that
pair. In particular, the frontend's public-artefact store contracts and the
Companion Packages implementations must agree; do not remove publication
permits or select an older dependency simply to make analysis pass.

The destination verification command and expected output are **not yet
verified**.

## What can go wrong and how to recover

An existing table is not evidence of Capell ownership. Stop if a migration silently adopts it. Do not change naming after tables have been populated without a separately reviewed migration.

A matching migration filename is not sufficient ownership evidence either:
an application can have created its own table with the same conventional name.
Retain that case in the coexistence fixture and stop if it is silently adopted.

A conventional migration suffix is also insufficient. Check the effective host
migration file that Laravel would execute, including a published file that
shadows a registered package migration. A retimestamped publication must match
the trusted package source or its supported deterministic publisher output.
Ownership checks must read the schema and ledger from the same connection.

The supporting Core regression run passed **60 tests, 241 assertions**, with
PHP 8.4 and an SQLite in-memory test database:

```sh
vendor/bin/pest packages/core/tests/Unit/TableNameTest.php packages/core/tests/Feature/TableNamingReviewTest.php packages/core/tests/Unit/Support/Packages/DeferredRuntimeReceiptContextTest.php packages/core/tests/Unit/Testing/ExtensionHarnessRuntimeRoleTest.php
```

These checks also cover deferred receipt ownership and runtime-role restoration.
They are framework regression evidence; the site's installation, MariaDB
coexistence and screenshot verification remain **not yet verified**. Use a
release containing the reviewed fixes before treating this as a rehearsal gate.

## Roll back

Before any content import, restore the destination backup and naming configuration together. Changing config alone does not rename populated tables.

## Next

Return to the [migration chapter list](index.md).
