# Migrating from Voyager to Capell

**Draft — the migration procedure is not yet verified end to end.** Use this
page set to prepare a disposable rehearsal. Do not use it to operate a live site.
Chapters gain executable transcripts and screenshots only after that phase has
been run and checked. A source review does not count as execution.

This guide is for an agency moving an existing Laravel application from Voyager
to Capell while retaining its application tables, public URLs and customer
journeys. Voyager Importer and Migration Assistant are optional packages; they
are not included merely by installing Core.

## Prepare the evidence

Keep one private evidence directory per rehearsal. Record the application,
package and framework revisions, command, working directory, start and finish
times and actual exit code. Keep reports free of secrets and personal data.
Before allocating a database, dependencies or screenshots, check available disk
space for the source, destination, backups and temporary copies together.

Use seeded records with fictitious names and reserved example email domains.
Use a consistent 1440 × 900 viewport and the light theme. Show the relevant
Capell result after installation or import. Old Voyager screenshots are not
required. Use command transcripts for checks without a meaningful admin screen.
Inspect every image at full size before committing it. Keep screenshots
beside these pages and give them alt text explaining the visible result.
Capell captures must use the shared screenshot runner and retain its receipt;
see the [visual standard](../../standards/documentation-visuals.md).

If the rehearsal uses a source build, first follow
[Verify a migration build from source](verify-build.md). Its checks and failure
recovery notes come from preparing the migration toolchain.

## Follow the chapters in order

| Chapter | Result to prove | Current evidence |
| --- | --- | --- |
| [Audit the source](audit.md) | BREAD, menus, settings, access rules and media inventoried | Schema-only inspection verified; admin inventory not yet verified |
| [Install beside Voyager](install.md) | Collision-safe destination without changing source tables | Not yet verified |
| [Configure table names](table-naming.md) | Default, prefix and alias behaviour; foreign tables refused | Supporting package checks passed; destination installation not yet verified |
| [Connect Migration Assistant](database-source.md) | Pinned database source and recorded provenance | Not yet verified |
| [Import and repeat](import.md) | Preview, execute and unchanged rerun | Not yet verified |
| [Reconcile counts](reconcile.md) | Every source row accounted for | Not yet verified |
| [Compare public pages](parity.md) | Equal URLs, HTML, SEO, structured data and headers | Not yet verified |
| [Check redirects](redirects.md) | Legacy routes retain their destinations and status | Not yet verified |
| [Test roles](roles.md) | Allowed and denied journeys for every role | Not yet verified |
| [Rehearse the freeze](rehearsal.md) | Measured repeatable operation and restore | Not yet verified |
| [Cut over and roll back](cutover.md) | Owner-operated switch with a bounded rollback window | Not yet verified |

For a same-database migration, use the importer's explicit restricted in-place
process with separate reader, writer and metadata-inspector accounts. An ordinary
source connection pointed at the destination is not sufficient. Bounded settings,
shared-user-reference and page rehearsals have passed; complete multi-entity
conversion and reconciliation remain unverified. Table aliases alone do not
establish write isolation. Keep the privilege and physical-table checks enabled.

## Next

Start with [Audit the source](audit.md).
