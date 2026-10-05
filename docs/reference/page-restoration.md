# Page restoration

Page restoration runs over the complete recorded member set in one database
transaction. The selected page, its required deleted ancestors and eligible
recorded descendants are read under row locks. Deletion membership comes from
locked current database state, never the deletion state of a stale model.
Membership is consumed per restored member and pruned on restore or permanent
purge. A Site restore preserves its own batch history. Independent Page
restoration consumes that Page's membership in open Site batches too, so a later
independent deletion cannot be restored by stale Site membership.

Untracked historical descendants are never inferred from timestamps. Only the
selected page and its required deleted ancestors can be recovered from that
history. Admin guidance explains this limit and notices name accessible
excluded descendants with instructions for separate recovery. Notices and trash
pagination use the page view policy and disclose neither inaccessible titles nor
counts. Visibility is evaluated afresh rather than retained across actions.

## Entry points and authorisation

The edit action, list bulk action and Recently Deleted use the Admin restore
action. There is no separate list row restore action. The Core model boundary
also checks every member, so direct `Page::restore()` and `restoreQuietly()` do
not bypass the acting user's policy or site scope. Unauthenticated console
maintenance is trusted; an unauthenticated web request is refused. Query-builder
updates are maintenance primitives without Eloquent model methods or policies
and must not be exposed as user restore endpoints.

Every member is authorised before restoration writes begin. Core re-reads the
members and rechecks authorisation before the cascade completes. If an ability
callback changes an earlier member's access and the final check fails, the
transaction rolls back. Core does not intercept SQL: ordinary database-backed
permission-cache population is supported. Policies and listeners are trusted
application callbacks, not a sandbox; this check does not promise to detect every
arbitrary mutation or external effect they can perform.

Site restoration locks the current Site and deletion batch, validates every Page
before restoring anything, and uses the same model boundary. A Page plan that
would expand outside the recorded Site batch is refused. Recorded Site routes,
including manual routes without a Page owner, receive collision checks.

## Model listeners and transaction boundary

Eloquent listeners run with the framework's normal semantics. Additional
`restoring`, `saving` and `updating` listeners are supported, including Publishing
Studio's draftable-model saving listener. A `restoring` listener returning
`false` cancels restoration as Eloquent defines. A cancellation or exception
rolls back all Page, translation, URL and deletion-membership rows written by the
cascade on its database connection. Quiet restoration keeps model events silent.
Each successful bulk selection has its own transaction.

Standard translation and URL restoration is batched; every Page still runs its
model restore lifecycle in parent-first order. Core emits one `PageSaved`
notification per restored member after commit. Availability-only restoration
does not create a duplicate content revision; listener changes to captured
Page attributes still create a revision. Core's cache invalidation and
content-graph work use the connection's `afterCommit` facility; an outer rollback
also discards that work. Listeners' side effects outside the transaction's
database are their responsibility. Use the framework's `afterCommit` callbacks,
`ShouldHandleEventsAfterCommit` for listeners/observers, or queued after-commit
facilities for cache, search and notification work that must not run after a
rolled-back cascade.

After-commit delivery is not a durable outbox or an exactly-once protocol. A
process failure can lose notifications; an after-commit listener can throw after
the database is already committed. That exception cannot undo committed rows.
The one-notification guarantee describes Core's dispatch, not listener delivery
or additional events emitted by extensions.

## Route collisions

The route key is `(site_id, language_id, url)` for enabled, live rows. The migration
indexes `(site_id, language_id, url, deleted_at)`; its nullable deletion column does
not itself enforce live uniqueness on SQLite or MySQL. Restoration compares
candidate and live rows in SQL using the database's collation and checks
candidates against each other before restoration writes. A different language
or site, or a disabled route, is not a conflict. Distinct enabled rows conflict
even with the same Page owner. Parent and translation slugs do not define public
route uniqueness.

Locks protect the examined rows. Server-backed concurrency, absent-key locking
and deadlock behaviour need server database verification; SQLite cannot prove
them. This boundary does not cover another connection, raw PDO or external I/O.
