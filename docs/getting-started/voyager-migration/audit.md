# Audit the Voyager install

**Draft — not yet verified end to end.**

## What this step achieves

Establish which data and behaviour belong to Voyager, and which belong to the application.

## Before you start

A schema-only SQL export and a disposable Voyager instance with seeded BREAD metadata, menus, settings and role assignments. Keep the original export immutable. A filename is not proof that an export contains no data.

## Procedure and exact commands

Inventory data_types and data_rows, including relationships, scopes and detail keys; menu nesting, named routes and parameters; setting keys and types without values; roles, permissions, primary and secondary role assignments; and original files under /storage/. Record custom tables and frontend Voyager calls separately. Do not infer row counts from table definitions.

For a Laravel application with a committed schema at the path below, this
read-only check counts tables and rejects data statements. Run it from the
application root. It does not execute SQL or display row values.

```bash
python3 - <<'PYTHON'
from pathlib import Path
import re,json
schema=Path('database/schema/mysql-schema.sql').read_text()
result={'tables':len(re.findall(r'CREATE TABLE `',schema)),'data_statements':len(re.findall(r'^\s*(?:INSERT|REPLACE|UPDATE|DELETE)\b',schema,re.M|re.I))}
print(json.dumps(result,sort_keys=True))
assert result['data_statements']==0, 'Use a schema-only source'
PYTHON
```

Verified against a structure-only rehearsal schema: exit 0 with
`data_statements` equal to 0. The table count depends on your application.
This is an initial inspection of a conventional generated SQL schema, not a
SQL security validator: it does not prove that arbitrary SQL is safe to execute.
The database inventory command and admin audit are **not yet verified**.

## What you should see

Voyager: BREAD list and a seeded relationship field. Capell: destination content and resource inventory after import. Alt text must identify the relationship or unsupported field the reader should notice.

Screenshots are **not yet captured or accepted**. Do not substitute screenshots
from a live site or an unrelated workflow.

## Check it worked

Compare the inventory with the actual source metadata and a filesystem inventory. Every BREAD and setting needs a destination or an explicit exclusion. Admin inventory and media checks are not yet verified.

The verification command and expected output are **not yet verified**.

## What can go wrong and how to recover

If an export contains INSERT, REPLACE, UPDATE or DELETE statements, treat it as data-bearing. Do not restore it into a screenshot environment. Generate seeded fixtures from an inspected schema instead.

## Roll back

Read-only inspection needs no database rollback. Delete only owned disposable reports after preserving the evidence; keep the original source unchanged.

## Next

Return to the [migration chapter list](index.md).
