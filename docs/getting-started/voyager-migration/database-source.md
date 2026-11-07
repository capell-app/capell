# Connect a database source to Migration Assistant

**Draft — not yet verified end to end.**

## What this step achieves

Establish a stable source identity and rollback provenance.

## Before you start

An isolated seeded source and destination, a read-only source account, a receiving site and an authorised destination actor. Ensure database credentials cannot enter reports.

## Procedure and exact commands

Record connection identity, source table names, schema fingerprint and one stable source label. Verify which entities are global and which require a site. Keep session IDs and before-images for updates. A same-database operation requires the explicit restricted in-place process and separate reader, writer and metadata-inspector profiles. Ordinary source connections do not provide that isolation.

The executable command transcript for this phase is **not yet verified**. Do not
copy a command from another release or infer flags from this draft. Add the exact
command, working directory and exit only after running it on disposable data.

## What you should see

Capell: database-source session and its destination site. Alt text should identify the source label and session state without exposing credentials.

Screenshots are **not yet captured or accepted**. Do not substitute screenshots
from a live site or an unrelated workflow.

## Check it worked

Prove source immutability, source-key persistence and rollback coverage for users, roles, settings, navigation, media, articles and pages. Not yet verified.

The verification command and expected output are **not yet verified**.

## What can go wrong and how to recover

Reject an unknown connection, a connection alias of the destination, a changed schema fingerprint or a missing actor. Do not substitute a live connection to make the rehearsal start.

A same-database source needs a separately verified physical write boundary. A
different connection name or a destination prefix does not establish one. Keep this phase **not yet verified** until restricted-account import,
repeat, source-immutability and rollback checks all run successfully.

## Roll back

Use the recorded session rollback only after its behaviour is proven. A complete database/storage restore remains necessary for changes outside session provenance.

## Next

Return to the [migration chapter list](index.md).
