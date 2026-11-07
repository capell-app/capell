# Smoke-test every role

**Draft — not yet verified end to end.**

## What this step achieves

Preserve staff access and prevent customer privilege escalation.

## Before you start

Disposable users for each staff role and a customer with no staff role. Map permissions explicitly after resource permissions have been generated. Disable real mail and external dispatch.

## Procedure and exact commands

Test both allowed and forbidden actions for each role, including direct URLs and mutations. Cover content editing, order operations, reports, cache actions, document review, settings and builder printing. Verify imported password hashes and secondary assignments without printing them.

The executable command transcript for this phase is **not yet verified**. Do not
copy a command from another release or infer flags from this draft. Add the exact
command, working directory and exit only after running it on disposable data.

## What you should see

Capell: permitted screen and a denied operation for a lower-privilege role. Alt text must explain the access boundary.

Screenshots are **not yet captured or accepted**. Do not substitute screenshots
from a live site or an unrelated workflow.

## Check it worked

Each acceptance item needs a feature assertion per applicable role and a browser smoke journey. A customer must not acquire staff role rows. Not yet verified.

The verification command and expected output are **not yet verified**.

## What can go wrong and how to recover

Do not grant super-admin to make a smoke test pass. Find the explicit mapping or policy failure and retest the allowed and denied paths.

## Roll back

Keep role and permission before-images with the global session. Restore assignments before reopening admin access, and confirm an independent administrator can still sign in.

## Next

Return to the [migration chapter list](index.md).
