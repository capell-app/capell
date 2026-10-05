# Page restoration

Page restoration is a transaction over the complete recorded member set. The
selected page, its required deleted ancestors and eligible recorded descendants
are read under row locks. Deletion membership is consumed per restored member;
a Site restore preserves its own batch history. Independent Page restoration
consumes that Page's membership in open Site batches too, so a later independent
deletion cannot be restored again by stale Site membership. Untracked historical
descendants are never inferred from timestamps. Admin notices and Recently Deleted
use the page view
policy and do not disclose inaccessible titles or counts. The resource's trash
query filters denied records before pagination and totals, including when live and
deleted pages are listed together. Policy evaluation eagerly loads the trashed
candidates on every query rather than retaining a permission snapshot across actions.

## Entry points and authorisation

The edit action, list bulk action and Recently Deleted use the Admin restore
action. There is no separate list row restore action. The Core model boundary also
checks every member, so direct `Page::restore()` and `restoreQuietly()` cannot
bypass the acting user's policy or site scope. There is no built-in page restore
API or console command. Unauthenticated console maintenance is a trusted operation;
an unauthenticated web request is refused.

Site restoration locks its current Site and deletion batch, validates every Page
member before restoring anything, and uses the same model boundary. A Page plan
that would expand outside the recorded Site batch is refused. All recorded Site
routes, including manual routes without a Page owner, receive collision checks.
Raw query-builder updates remain a maintenance primitive: they do not run Eloquent
model methods or policies and must not be exposed as a user restore endpoint.

Permission callbacks must be read-only. Attempting a non-SELECT statement on the
restoration connection during a permission check refuses the transaction before
that statement executes. The final model check runs after Admin notice preparation;
the intrinsic cancelling lifecycle hooks use the same read-only scope. Code that
mutates another connection, uses raw PDO, performs I/O or changes arbitrary global
state from a policy is outside this contract. These PHP callbacks are trusted
application code, not a sandbox for untrusted extensions.

## Lifecycle and extension boundary

Only the model's intrinsic cancelling hooks, registered during its own boot, may
run during a restore. Additional exact `restoring`, `saving` or `updating` hooks
(including hooks registered before model boot) refuse the cascade before they run.
Custom event mappings for those hooks are also refused. This restriction applies
to single pages as well as larger cascades. Admin shows a translated explanation
that access changed or an extension needs updating; no member is restored.

Non-halting model events are captured with their event-time model state and
registered with the connection's `afterCommit` facility. Wildcard event consumers
are notifications: during restoration they also run after commit and cannot veto
or change the plan. Standard relation restoration happens inside the transaction,
before notifications, so callbacks see committed pages, translations and URLs.
Outer rollback and savepoint rollback discard their queued callbacks. Quiet
restoration stays silent even when an outer transaction commits later. Successful
bulk selections remain independent transactions.

After-commit listeners cannot cancel a committed restore. Delivery failures are
reported separately and never turn a committed restore into a refusal; this is not
a durable outbox or an exactly-once delivery protocol. A process failure after
commit can lose notifications. Supporting custom
cancelling extension hooks safely needs a separate, explicit pure-guard contract
that consumes an immutable complete restore plan, followed by durable post-commit
notifications. Do not add exceptions for individual extension listeners to bypass
the refusal.

## Route collisions

The route key is `(site_id, language_id, url)` for enabled, live rows. The migration
indexes `(site_id, language_id, url, deleted_at)`; its nullable deletion column does
not by itself enforce live uniqueness on SQLite or MySQL. Restoration therefore
compares candidate and live rows in SQL, using the database's collation, and checks
candidates against each other. A different language or site, or a disabled route,
is not a conflict. Distinct enabled route rows conflict even with the same Page
owner. Parent and translation slug fields do not define public route uniqueness.

The transaction locks and checks protect the examined rows. Server-backed
concurrency, absent-key locking and deadlock behaviour require the hosted database
lanes; SQLite cannot prove them.
