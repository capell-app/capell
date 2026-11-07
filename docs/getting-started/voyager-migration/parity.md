# Compare public pages

**Draft — not yet verified end to end.**

## What this step achieves

Prove that changing the CMS preserves the public site.

## Before you start

A seeded baseline from the legacy renderer, fixed URL set, matching destination content and a documented normalisation policy. Use local instances only.

## Procedure and exact commands

Capture the complete sitemap URL set and pagination routes. Compare status, normalised HTML, canonical URLs, meta tags, JSON-LD and relevant headers. Exclude only agreed volatile values such as CSRF tokens, nonces and timestamps. Include noindex and cache-bypass behaviour.

The executable command transcript for this phase is **not yet verified**. Do not
copy a command from another release or infer flags from this draft. Add the exact
command, working directory and exit only after running it on disposable data.

## What you should see

Capell: the corresponding page and metadata. Alt text should identify the preserved URL, content hierarchy or canonical.

Screenshots are **not yet captured or accepted**. Do not substitute screenshots
from a live site or an unrelated workflow.

## Check it worked

Require equal sitemap sets, zero status changes and equal canonical/meta/JSON-LD hashes. Investigate every normalised HTML difference. Exercise builder, cart, account and sandbox checkout separately; compare performance with the same conditions. Not yet verified.

Read the sitemap served by each rehearsal application. A sitemap file in the
checkout can be stale and cannot prove what either application is serving.
Keep sitemap membership separate from extra redirect and pagination probes.

If reports need privacy scrubbing, assign an opaque identity to each URL before
scrubbing its text. Matching anonymised URL strings can merge different paths
or change when the private-value list changes. Refuse an older baseline that
has lost its original identities and recapture it from the authorised fixtures;
do not reconstruct identities from scrubbed names. Keep generated digests
separate from source JSON-LD and other fields that must still be scrubbed.

The verification command and expected output are **not yet verified**.

## What can go wrong and how to recover

A 200 page can still have wrong structured data, caching or asset paths. Keep failure reports, fix the responsible adapter or mapping and recapture only after the cause is understood.

## Roll back

Keep the legacy renderer and its baseline available until parity passes. Switch the rehearsal back to its previous release and database together if the replacement cannot render the content.

## Next

Return to the [migration chapter list](index.md).
