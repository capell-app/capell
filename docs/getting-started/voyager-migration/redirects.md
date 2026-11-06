# Preserve redirects

**Draft — not yet verified end to end.**

## What this step achieves

Keep old links reaching the same destination without loops or chains.

## Before you start

A versioned redirect inventory containing source path, expected status and target, plus any web-server rules. Retain the old routing configuration.

## Procedure and exact commands

Probe redirects without automatically following them, then check the destination. Include query parameters, encoded paths, trailing slashes, pagination and named-route menu items. Preserve historical route-name aliases where application code still calls them.

The executable command transcript for this phase is **not yet verified**. Do not
copy a command from another release or infer flags from this draft. Add the exact
command, working directory and exit only after running it on disposable data.

## What you should see

Voyager: seeded menu link or source URL. Capell: matching redirect entry and resolved destination. Alt text should name the old-path to new-path relationship.

Screenshots are **not yet captured or accepted**. Do not substitute screenshots
from a live site or an unrelated workflow.

## Check it worked

Require the agreed redirect status and Location for every rule, reachable destinations, no cycles and no unintended extra hops. Not yet verified.

The verification command and expected output are **not yet verified**.

## What can go wrong and how to recover

Separate application redirects from proxy/server rules. A missing Location or an HTML fallback is a failed redirect even if a browser eventually displays a page.

## Roll back

Restore the versioned redirect configuration and application release together. Do not delete historical URLs while investigating a mapping failure.

## Next

Return to the [migration chapter list](index.md).
