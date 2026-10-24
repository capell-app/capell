# Admin upload ownership

Regular Admin uploads and guarded native Filament upload subclasses accept an
existing path only from the current field's persisted record or a server-issued
upload grant. Submitted paths never create grants. A grant allows a validated
upload to survive a halted save after its temporary file has been removed.

Grants live in the persistent `admin_upload_grants` database table, independently
of the application's cache driver and session snapshots. The additive Admin
migration must run before the new upload code serves edit requests. See the
[upgrade deployment order](../../../docs/operations/upgrading.md#admin-upload-ownership-registry).
The registry uses the default database connection and honours its configured table
prefix, and reads use the write connection to avoid replication lag. Every application node must use the same database for these grants.

When storage occurs inside a content transaction, a connection-local journal
reasserts issued grants after Laravel reports a rollback. It preserves the
original expiry across nested savepoints and outer rollbacks, and journals
revocations too. It uses the same connection, including SQLite `:memory:`; no
second database connection or cache fallback is required. The journal is cleared
when the outer transaction ends, and also when the next outer transaction begins,
so a commit that fails terminally never replays into a later transaction. It is
request-scoped; three stateless listeners are registered once per application and
resolve the current journal when a transaction event fires, so a long-lived
worker never accumulates listeners or retains finished requests.

Each grant has fixed-length SHA-256 scope and path hashes, a stored path and an
indexed expiry. The scope includes the session ID and CSRF token, actor, Livewire
component class and ID, enclosing form and local record identities, field path,
disk, directory, and the full
enclosing server-derived Builder block-type chain. Builder traversal continues
through `model([])` and changed model roots; persisted-record lookup still respects
its own model boundary. Changing a block type, actor, form, field, record or session
invalidates the old grant.

Grant insertion, candidate lookup and deletion use independent atomic database
statements, without a shared index or read-modify-write cycle. Reissuing a grant
never renews its expiry, which is set from `session.lifetime`. `paths()` only reads
unexpired matching grants and checks file existence; it never writes, prunes or
refreshes TTL. The upload adapters capture the deletion callback and explicitly revoke its
path after successful physical deletion, even though Filament has already
removed that path from field state. Native subclass callbacks remain intact;
failed deletions retain their authority. Validation also removes grants for
missing files. Expired grants cannot authorise a path even before physical pruning.

Each grant operation opportunistically removes at most 100 expired rows across
all scopes, including abandoned sessions and forms. The hourly
`capell:admin-prune-upload-grants` command removes at most 1,000 expired rows per
invocation using a single bounded delete, with the package's usual scheduler
overlap and single-server guards. Keep Laravel's scheduler running. The same
command can be run manually, including repeatedly to clear a backlog; it never
deletes uploaded files or live grants. Grant expiry does not depend on the
scheduler running on time.

The migration uses short explicit index names and a composite unique key on two
64-character hashes; full paths remain unindexed text. Pruning uses a limited
derived-table subquery, avoiding engine-specific `DELETE ... LIMIT` syntax. These
shapes support MySQL, MariaDB, PostgreSQL and SQLite.

`capell:admin-install` and `capell:admin-upgrade` both publish and migrate the
Admin schema, stopping if either step fails. `vendor:publish
--tag=capell-admin-migrations` and Capell package publication preserve the same
canonical migration filename, so a later `capell:upgrade --force` does not create
a second ledger entry or rollback owner.
