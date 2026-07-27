# Database Search Dialect Design

## Goal

Complete CAP-0042's typed database compatibility seam for two companion-package
search requirements:

- search JSON values using either a bound scalar or a correlated SQL expression;
- build full-text predicates and relevance expressions without exposing database
  metadata queries or engine-specific SQL to callers.

The implementation remains in Core. Companion packages only supply
grammar-wrapped column expressions, a declared index definition, and user input.

## Interfaces

`DatabaseQueryDialect::jsonSearch()` accepts the JSON document and needle as
`SqlFragment` values. A scalar caller uses `SqlFragment::value()`. A correlated
caller supplies a grammar-wrapped column through `SqlFragment::raw()`. Each
dialect owns wildcard composition and binding order.

`DatabaseQueryDialect::fullTextSearch()` accepts a non-empty list of searchable
expressions, a user query, and a Core-controlled native-mode flag. It returns a
`DatabaseFullTextSearch` value containing predicate and relevance
`SqlFragment` instances generated from one normalized query.

`DatabaseSchemaDialect::hasCompatibleFullTextIndex()` decides whether the
declared `DatabaseIndexDefinition` is backed by a usable native index on the
active connection. MySQL and MariaDB accept a covering full-text index.
PostgreSQL requires the declared GIN expression index. SQLite always reports
false.

`DatabasePlatformRegistry::fullTextSearch()`, exposed through
`CapellDatabase`, resolves the platform, asks its schema dialect about the
index, and passes the resulting mode to the query dialect. Callers never query
`information_schema`, PostgreSQL catalogues, or capability flags.

## Engine Behaviour

MySQL and MariaDB use `JSON_SEARCH` for JSON substring search. PostgreSQL uses
`jsonb_path_query` with `ILIKE`. SQLite uses `json_each` or `json_tree` with
`LIKE`. User values remain bindings; only caller-supplied, grammar-wrapped
expressions enter SQL.

With a compatible index, MySQL and MariaDB use `MATCH ... AGAINST`; PostgreSQL
uses `to_tsvector`, `plainto_tsquery`, and `ts_rank_cd`. Without one, all
engines use the same portable fallback:

1. normalize and split the query into non-empty whitespace-delimited terms;
2. require every term to occur in at least one searchable expression;
3. sum per-term, per-expression matches for deterministic relevance.

LIKE metacharacters are escaped before binding. Expression bindings are
repeated in exact SQL placeholder order.

## Verification

Tests exercise the public registry and dialect seams rather than private
helpers. SQLite, MariaDB, MySQL 8, and PostgreSQL 16 must prove:

- correlated JSON needle matches and mismatches;
- scalar and expression bindings remain ordered and injection-safe;
- separated multi-term queries require all terms;
- higher term coverage ranks above lower coverage;
- native mode is selected only when the declared index is compatible;
- the portable fallback preserves the same matching contract.

Focused compatibility tests, extension contract checks when required, Pint,
and `composer analyze` must pass before the branch is pushed.
