# Exact JSON Search and Prefix Full-Text Design

## Context

The CAP-0042 database compatibility layer currently has two semantic gaps:

1. `jsonSearch` intentionally performs substring search, so a correlated widget
   key of `hero` also matches `hero-banner`. Layout lookup needs exact string
   scalar matching without changing existing substring consumers.
2. The portable full-text fallback matches query text within longer values, but
   native MySQL, MariaDB, and PostgreSQL queries currently require whole
   lexemes. A query term such as `port` therefore misses `portable` after a
   compatible native index is installed.

## Exact JSON String Search

Add this method to the typed `DatabaseQueryDialect` contract:

```php
public function jsonExactSearch(
    SqlFragment $expression,
    SqlFragment $needle,
    string $path = '$',
): SqlFragment;
```

The seam is explicitly for exact JSON string scalar search. It accepts a
`SqlFragment` needle so a caller can correlate a JSON value with another column
without interpolating data into SQL. It does not claim typed equality for
numeric, boolean, array, object, or JSON null values.

`jsonSearch` remains unchanged and continues to mean substring search.

### Dialect behaviour

- MySQL and MariaDB use `JSON_SEARCH` without surrounding `%` wildcards.
  Because `JSON_SEARCH` treats `%` and `_` as pattern operators, the needle is
  escaped inside SQL with nested `REPLACE` calls for `!`, `%`, and `_`, and `!`
  is supplied as the constant escape character. The correlated needle fragment
  remains bound in placeholder order.
- PostgreSQL selects values through the supplied `jsonpath`, converts each JSON
  scalar to text, and compares it with the correlated needle using `=`.
- SQLite reuses `SqliteJsonSearchPath` for mixed object and array wildcard
  traversal, then compares only the terminal `json_extract` text with the
  correlated needle using `=`. Non-wildcard paths retain a scoped `json_tree`
  fallback.

The exact path `$.*.widgets[*].widget_key` must match `hero` stored at that
location, and must reject `hero-banner` and a `hero` value stored elsewhere in
the document.

## Prefix Native Full-Text Search

The existing whitespace tokenization, lower-casing, de-duplication, empty-query
handling, native-index selection, and portable fallback stay in place. Only the
native query representation changes.

### MySQL and MariaDB

Each normalized term becomes a required Boolean prefix term:

```text
+port* +arch*
```

Boolean operator characters originating in user terms are escaped before the
required leading `+` and controlled trailing `*` are added. The resulting query
is always passed as a binding.

Both predicate and relevance use:

```sql
MATCH (...) AGAINST (? IN BOOLEAN MODE)
```

Sharing the same Boolean prefix query ensures rows admitted by the predicate
receive term-aware native relevance instead of a zero score from the former
whole-word natural-language query.

### PostgreSQL

Each normalized term becomes a quoted prefix lexeme, with embedded quotes and
backslashes doubled according to `tsquery` input rules:

```text
'port':* & 'arch':*
```

The terms are joined with `&` to preserve AND semantics. Predicate and
`ts_rank_cd` relevance both use the same bound value through
`to_tsquery('simple', ?)`.

### Portable fallback

SQLite and non-native calls retain the existing portable `LIKE` predicate and
per-term/per-expression relevance. The native engines need to preserve the
required observable subset: `port` matches `portable`, every query term is
required, and denser term coverage ranks before separated coverage.

## Public-Seam Tests

`DatabaseCompatibilityTest` will exercise the public dialect and registry
seams:

1. `jsonExactSearch` binding generation for every family.
2. Executable exact JSON matching with a correlated column needle on SQLite,
   MariaDB, MySQL 8, and PostgreSQL 16.
3. Native full-text binding generation and escaping without SQL interpolation.
4. A real indexed full-text dataset where:
   - `port arch` matches `portable archive`;
   - the two prefixes may be separated across indexed columns;
   - a row missing the second prefix does not match;
   - dense coverage ranks before separated coverage.

The same dataset runs through SQLite's fallback and each server engine's native
index path.

## Full-Text Index Compatibility Cache

`DatabasePlatformRegistry::fullTextSearch()` currently calls
`hasCompatibleFullTextIndex()` for every search. On MySQL, MariaDB, and
PostgreSQL that performs a schema metadata query, putting index inspection on
the public search hot path.

Make `DatabasePlatformRegistry` a Laravel scoped service and keep a request-local
`WeakMap` inside it:

```text
Connection object
  -> database name + complete DatabaseIndexDefinition fingerprint
     -> compatible boolean
```

Connection identity is represented by the `WeakMap` key rather than a reusable
integer object ID. The fingerprint includes the current database plus table,
index name, ordered columns, prefix lengths, and uniqueness. Repeated searches
for the same connection/database/index inspect metadata once per request or
queue-job scope.

Laravel discards scoped instances at long-running worker lifecycle boundaries,
so the cache cannot leak across Octane requests or queue jobs. Add explicit
registry methods to forget one index or every index for a connection, and to
flush the complete compatibility cache. Same-request code that creates or drops
an index must invalidate after DDL before searching again.

The registry test will prove one schema inspection for repeated searches,
another inspection after per-index invalidation, isolation for a second
connection, and a fresh cache after the scoped container instance is forgotten.
The Core provider and Octane lifecycle documentation will list the registry as
scoped.

## Quality and Publication

Apply the deterministic Rector output already reported for
`SqliteQueryDialect`:

- use `$searchPath instanceof SqliteJsonSearchPath`;
- iterate `array_keys($searchPath->collectionPaths)` when only collection
  indexes are needed.

Run the focused red/green tests, repository Rector check, Pint, `composer
analyze`, and the compatibility file on SQLite, MariaDB, MySQL 8, and
PostgreSQL 16. Preserve concurrent PR changes and push the exact verified head
to open PR #166 unless it merges during implementation.
