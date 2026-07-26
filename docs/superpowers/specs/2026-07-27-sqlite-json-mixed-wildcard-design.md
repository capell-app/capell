# SQLite Mixed-Wildcard JSON Search Design

## Problem

Capell's typed database query dialect accepts JSON search paths such as
`$.*.widgets[*].widget_key`. Layout JSON stores its containers as keyed object
members and each container owns a `widgets` array.

MySQL, MariaDB, and PostgreSQL can evaluate that path directly. SQLite JSON1
cannot: `json_each(document, '$.*.widgets')` returns no rows because JSON1 path
arguments do not traverse object wildcards. Searching from `$` instead would
change the contract and could match the same needle at an unrelated location.

## Decision

SQLite will parse the portable subset of JSON path syntax used by typed
`jsonSearch` calls:

- root: `$`
- named members: `.member`
- object wildcard: `.*`
- array wildcard: `[*]`

The parser will convert each wildcard into a collection traversal path and keep
the member path after the final wildcard as the value extraction path. For
example:

```text
$.*.widgets[*].widget_key
collection paths: $, $.widgets
value path:       $.widget_key
```

The SQLite dialect will compile those collection paths into chained
`json_each` joins. Each join consumes the previous row's `value`, and the final
predicate applies `json_extract` only at the parsed value path. The SQL remains
generic to the parsed path and contains no Layout-specific member names.

Paths outside this deliberately small wildcard grammar will continue through
the existing `json_tree` fallback. Existing paths such as `$[*].data` and
`$.widgets[*].widget_key` remain supported by the same parser/compiler.

## Code Shape

Add a small immutable SQLite-specific path value object beside the SQLite query
dialect. It owns parsing and exposes:

- a non-empty ordered list of collection paths;
- the terminal value path.

`SqliteQueryDialect` remains responsible for SQL generation and binding order.
Separating parsing from compilation keeps the dialect method readable and
allows parser behaviour to be unit-tested through the public typed query seam.

## Behaviour and Verification

The executable compatibility test will use the exact path
`$.*.widgets[*].widget_key` on every supported engine. It will prove:

1. a needle stored as `widget_key` inside a keyed container's `widgets` array
   matches;
2. a missing widget key does not match even when the same needle exists
   elsewhere in the JSON document.

The test will also assert SQLite's generated binding order so expression and
needle fragments remain safe and deterministic. Verification will run the
focused compatibility test against SQLite, MariaDB, MySQL 8, and PostgreSQL,
then run Core static analysis and formatting.
