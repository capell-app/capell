# Preview, import and repeat

**Draft — not yet verified end to end.**

## What this step achieves

Move supported content and prove an unchanged source produces a no-op.

## Before you start

Approved mappings, installed optional dependencies, destination layouts/blueprints, role mappings, locale mappings and accessible original media. Back up the destination and freeze the rehearsal source.

## Procedure and exact commands

Run inventory and preview first. Resolve unsupported mappings and URL conflicts before execution. Execute global and site-owned entities in the dependency order the installed command supports. Record each session separately. Repeat the exact selections, source label, site and mappings.

### Verified settings and shared-user commands

Settings preview, execution and an unchanged rerun were verified in an installed
application on MariaDB 10.11.19. Shared-user reference import and its unchanged
rerun also passed. Site-content reconciliation and the complete migration remain
**not yet verified**.

For a source and destination in the same database, first prepare a private
configuration cache using the importer's
[dedicated process requirements](https://github.com/capell-app/capell-packages/blob/main/packages/voyager-importer/docs/migration-guide.md#dedicated-restricted-cli-process).
The default must already be the restricted writer. Keep administrative credentials
out of this cache and the command environment. The installed packages must contain
the in-place process implementation; an older released command may not support it.

Set `IMPORT_CONFIG_CACHE` to that private cache's absolute path, `SOURCE_LABEL` to
your registered source label and `ACTOR_ID` to the existing migration operator's
ID. From the receiving application root, the verified preview command is:

```sh
env -i PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin \
  APP_ENV=local APP_CONFIG_CACHE="$IMPORT_CONFIG_CACHE" \
  php -d zend.exception_ignore_args=1 artisan voyager-importer:import \
  --in-place --in-place-process --source-label="$SOURCE_LABEL" \
  --only=settings --actor-id="$ACTOR_ID" --json --no-interaction
```

It exited 0 and returned `write_plan_digest`. Review the plan, then set
`WRITE_PLAN_DIGEST` to that value. The verified execution command is:

```sh
env -i PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin \
  APP_ENV=local APP_CONFIG_CACHE="$IMPORT_CONFIG_CACHE" \
  php -d zend.exception_ignore_args=1 artisan voyager-importer:import \
  --in-place --in-place-process --source-label="$SOURCE_LABEL" \
  --only=settings --actor-id="$ACTOR_ID" --json --no-interaction \
  --execute --force --write-plan-digest="$WRITE_PLAN_DIGEST"
```

It exited 0. Running the same execution again also exited 0, reporting zero
created and updated settings and only skipped settings. The destination settings
were byte-for-byte unchanged by the rerun; protected source tables and users were
unchanged by both executions. `--force` here permits unattended execution; it does
not resolve destination conflicts.

Shared-user references were verified in separate sessions using the same source
label and the same commands with `--only=users`, obtaining a new preview digest.
The first run linked existing primary keys; the repeat skipped them. Neither run
created or updated a user. Keep these global sessions separate from site imports.

A seeded page also passed import and an unchanged rerun in the installed app.
The source SEO description matched the imported translation, the slug remained
intact, and protected source rows stayed unchanged. That rehearsal explicitly
reported unsupported custom heading and author fields; it does not establish
complete content mapping or public-page parity. The full site-command transcript
remains **not yet verified**.

## What you should see

Capell: completed session report followed by the unchanged rerun report. Alt text must call out zero created and updated rows on the rerun.

Screenshots are **not yet captured or accepted**. Do not substitute screenshots
from a live site or an unrelated workflow.

## Check it worked

For each entity retain created, updated, skipped and failed totals. The unchanged rerun must have zero created, updated and failed rows. Test a destination-edited record and verify it is reported rather than overwritten. Not yet verified.

The settings and shared-user commands above were verified. Full site-entity
reconciliation and the destination-edited conflict check are **not yet verified**.

## What can go wrong and how to recover

If a default layout is reported missing, check its scope in Capell. Global layouts
have no site owner and are valid targets. Use an importer that resolves a matching
site-specific layout first and then a global layout; the installed-app rehearsal
exposed and verified this resolver correction.

A partial session is not a clean retry. Read its failure and provenance before resuming. Do not enable force flags merely to bypass conflicts; prove their meaning against the installed version.

Real production data contains states a synthetic fixture rarely has. Decide each one
explicitly and report it rather than refusing the whole import or importing it silently:
placements of deleted pages, pages whose author was deleted, page media rows with no
image, body-only media files missing from the source disk, and slugs containing spaces
or colons. Voyager stores an empty text setting as `NULL`; a typed Capell settings
class cannot hold `null`, so map it to an empty string. Carry source creation and
update dates in page meta, because the importer refuses direct timestamp writes.

## Roll back

Roll back site sessions before their prerequisite global sessions. Verify updates restore before-images and unrelated destination rows survive. Restore backup if effects fall outside recorded provenance.

## Next

Return to the [migration chapter list](index.md).
