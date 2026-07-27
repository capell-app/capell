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

## Weighted Search Expressions

Replace the full-text expression list with a strict typed boundary:

```php
final readonly class DatabaseSearchExpression
{
    public function __construct(
        public SqlFragment $expression,
        public float $weight = 1.0,
    ) {}
}
```

Weights must be finite and greater than zero. The dialect and registry contracts
accept a non-empty list of this type only; plain fragments are not silently
normalized because the boundary is experimental and production callers need to
make weight handling explicit.

The combined native full-text predicate still uses all configured expressions
and the engine's compatible combined index. Relevance uses one portable formula
on every database family: for each normalized query term and each expression,
add that expression's weight when its text contains the term. This preserves
term-aware field weights and deterministic cross-engine ordering.

Portable relevance is intentional even in native mode. MySQL and MariaDB cannot
calculate arbitrary per-column full-text scores from a combined `(title, body)`
index: `MATCH(title)` requires a separate matching full-text index and fails
against only the combined index. Requiring one index per weighted expression
would be an unacceptable consumer schema burden. Native full-text therefore
selects candidates efficiently while portable weighted coverage ranks the
matched rows.

Add `DatabaseSearchExpression` as an experimental DTO in the generated
extension-surface catalogue and document the portable relevance contract.

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
5. Weighted expressions where terms in a stronger field rank above the same
   terms in a weaker field, invalid zero/negative/non-finite weights fail, and
   SQL placeholder bindings retain expression, pattern, and weight order.

The same dataset runs through SQLite's fallback and each server engine's native
index path.

## Full-Text Index Compatibility Cache

`DatabasePlatformRegistry::fullTextSearch()` currently calls
`hasCompatibleFullTextIndex()` for every search. On MySQL, MariaDB, and
PostgreSQL that performs a schema metadata query, putting index inspection on
the public search hot path.

Keep `DatabasePlatformRegistry` as a Laravel scoped service, but inject a
process-lived `FullTextIndexCompatibilityCache` singleton. The cache is bounded
to 256 least-recently-used entries:

```text
stable non-secret connection/config fingerprint
  + database name
  + complete DatabaseIndexDefinition fingerprint
     -> compatible boolean
```

The connection fingerprint includes only metadata identity fields needed for
correctness: connection name, driver, database, host, port, Unix socket, table
prefix, and read/write host configuration. Credentials and connection URLs are
never retained. The index fingerprint includes table, index name, ordered
columns, sorted prefix lengths, and uniqueness.

The process-lived lifetime is intentional: a public search normally resolves
the scoped registry and calls `fullTextSearch()` once, so request-local
memoization would still inspect schema metadata on every request. The bounded
cache survives scoped registry resets and equivalent connection objects while
avoiding unbounded tenant/config growth.

Add explicit registry methods to forget one index or every index for a logical
connection, and to flush the complete compatibility cache. Same-process code
that creates or drops an index must invalidate after DDL before searching
again. Deploys must restart long-running workers after migrations, matching
Capell's existing runtime refresh guidance.

The registry test will prove one schema inspection across repeated scoped
container resolutions, another inspection after per-index invalidation,
separate entries when database or connection configuration changes, eviction
at the bound, and a fresh inspection after a complete flush. The Octane
lifecycle inventory will classify the mutable singleton as a bounded,
explicitly invalidated process cache rather than request-shaped state.

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
