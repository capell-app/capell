# Database Search Dialects Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add portable correlated JSON search and index-aware full-text predicate/relevance generation to the Core database compatibility platform.

**Architecture:** `SqlFragment` remains the only SQL-expression value crossing the query-dialect seam. A cohesive `DatabaseFullTextSearch` result keeps predicate and relevance generation aligned, while `DatabasePlatformRegistry` hides native-mode selection behind schema-dialect index compatibility.

**Tech Stack:** PHP 8.4, Laravel 12 database connections and grammars, Pest, SQLite JSON1, MySQL/MariaDB full-text search, PostgreSQL text search.

---

### Task 1: Correlated JSON Search

**Files:**
- Modify: `packages/core/src/Contracts/Database/DatabaseQueryDialect.php`
- Modify: `packages/core/src/Support/Database/QueryDialects/MySqlQueryDialect.php`
- Modify: `packages/core/src/Support/Database/QueryDialects/PostgresQueryDialect.php`
- Modify: `packages/core/src/Support/Database/QueryDialects/SqliteQueryDialect.php`
- Modify: `packages/admin/src/Filament/Concerns/ApplySearchRelationsTable.php`
- Test: `packages/core/tests/Unit/Support/Database/DatabaseCompatibilityTest.php`

- [ ] **Step 1: Write failing public-seam tests**

Add executable rows containing a JSON document and a separate needle column.
Build the predicate through:

```php
$dialect->jsonSearch(
    SqlFragment::raw('meta'),
    SqlFragment::raw('needle'),
    '$.widgets[*].widget_key',
)->applyWhere($query);
```

Assert a matching key returns a row, a different key does not, and bound
document/needle expressions retain placeholder order.

- [ ] **Step 2: Verify the new signature and behavior fail**

Run:

```bash
./vendor/bin/pest packages/core/tests/Unit/Support/Database/DatabaseCompatibilityTest.php \
  --configuration=phpunit.xml --filter='correlated JSON'
```

Expected: failure because `jsonSearch()` still requires a string needle.

- [ ] **Step 3: Implement the typed needle**

Change the contract to:

```php
public function jsonSearch(
    SqlFragment $expression,
    SqlFragment $needle,
    string $path = '$',
): SqlFragment;
```

MySQL composes `CONCAT('%', needle, '%')` inside `JSON_SEARCH`. PostgreSQL
compares extracted text using `ILIKE ('%' || needle || '%')`. SQLite compares
JSON1 values using `LIKE ('%' || CAST(needle AS TEXT) || '%')`. Repeat both
document and needle bindings in exact SQL placeholder order.

Update scalar callers to pass `SqlFragment::value($searchTerm)`.

- [ ] **Step 4: Verify correlated and scalar JSON search**

Run the compatibility test file. Expected: all SQLite tests pass, with only
server-engine tests skipped.

- [ ] **Step 5: Commit**

```bash
git add packages/core/src/Contracts/Database/DatabaseQueryDialect.php \
  packages/core/src/Support/Database/QueryDialects \
  packages/core/tests/Unit/Support/Database/DatabaseCompatibilityTest.php \
  packages/admin/src/Filament/Concerns/ApplySearchRelationsTable.php
git commit -m "feat(database): support correlated json search"
```

### Task 2: Cohesive Portable Full-Text Search

**Files:**
- Create: `packages/core/src/Data/Database/DatabaseFullTextSearch.php`
- Modify: `packages/core/src/Contracts/Database/DatabaseQueryDialect.php`
- Modify: `packages/core/src/Support/Database/QueryDialects/AbstractQueryDialect.php`
- Modify: `packages/core/src/Support/Database/QueryDialects/SqliteQueryDialect.php`
- Test: `packages/core/tests/Unit/Support/Database/DatabaseCompatibilityTest.php`

- [ ] **Step 1: Write failing fallback tests**

Execute a query over rows where `alpha` and `beta` are separated across text
and columns. Assert the predicate requires both terms and relevance ranks a row
matching more term/column pairs ahead of a row matching fewer.

- [ ] **Step 2: Verify the full-text interface is absent**

Run the focused test filter and expect an undefined-method failure.

- [ ] **Step 3: Add the cohesive result and fallback**

Create:

```php
final readonly class DatabaseFullTextSearch
{
    public function __construct(
        public SqlFragment $predicate,
        public SqlFragment $relevance,
        public bool $native,
    ) {}
}
```

Add this query-dialect method:

```php
/**
 * @param non-empty-list<SqlFragment> $expressions
 */
public function fullTextSearch(
    array $expressions,
    string $query,
    bool $native = false,
): DatabaseFullTextSearch;
```

`AbstractQueryDialect` normalizes whitespace terms, escapes `!`, `%`, and `_`,
ANDs term groups, ORs expressions within a group, and sums per-expression CASE
matches. Empty normalized input returns predicate `0 = 1` and relevance `0`.

- [ ] **Step 4: Verify fallback semantics and bindings**

Run the compatibility file. Expected: separated terms match, missing terms do
not, higher coverage ranks first, and hostile query text appears only in
bindings.

- [ ] **Step 5: Commit**

```bash
git add packages/core/src/Data/Database/DatabaseFullTextSearch.php \
  packages/core/src/Contracts/Database/DatabaseQueryDialect.php \
  packages/core/src/Support/Database/QueryDialects \
  packages/core/tests/Unit/Support/Database/DatabaseCompatibilityTest.php
git commit -m "feat(database): add portable full text search"
```

### Task 3: Index-Aware Native Search

**Files:**
- Modify: `packages/core/src/Contracts/Database/DatabaseSchemaDialect.php`
- Modify: `packages/core/src/Support/Database/SchemaDialects/MySqlSchemaDialect.php`
- Modify: `packages/core/src/Support/Database/SchemaDialects/PostgresSchemaDialect.php`
- Modify: `packages/core/src/Support/Database/SchemaDialects/SqliteSchemaDialect.php`
- Modify: `packages/core/src/Support/Database/QueryDialects/MySqlQueryDialect.php`
- Modify: `packages/core/src/Support/Database/QueryDialects/PostgresQueryDialect.php`
- Modify: `packages/core/src/Support/Database/DatabasePlatformRegistry.php`
- Modify: `packages/core/src/Facades/CapellDatabase.php`
- Test: `packages/core/tests/Unit/Support/Database/DatabaseCompatibilityTest.php`

- [ ] **Step 1: Write failing native-selection tests**

Create the declared full-text index on the active server engine, call:

```php
$search = CapellDatabase::fullTextSearch(
    $connection,
    $index,
    [SqlFragment::raw($grammar->wrap('title')), SqlFragment::raw($grammar->wrap('body'))],
    'alpha beta',
);
```

Assert `native` is true on compatible MySQL, MariaDB, and PostgreSQL indexes,
false on SQLite or a missing index, and predicate/relevance execute safely.

- [ ] **Step 2: Add schema compatibility**

Add:

```php
public function hasCompatibleFullTextIndex(
    DatabaseIndexDefinition $index,
    Connection $connection,
): bool;
```

MySQL/MariaDB use Laravel schema metadata and accept a full-text index covering
all declared columns. PostgreSQL requires the named GIN index generated by the
schema dialect. SQLite returns false. Metadata errors return false.

- [ ] **Step 3: Add native query dialects**

MySQL/MariaDB return `MATCH (...) AGAINST (? IN BOOLEAN MODE)` and natural
`MATCH (...) AGAINST (?)` relevance with normalized terms bound separately.
PostgreSQL builds one `to_tsvector('simple', ...)`, uses
`plainto_tsquery('simple', ?)` for the predicate, and `ts_rank_cd` for
relevance. The expression used by PostgreSQL matches `fullTextIndex()`.

- [ ] **Step 4: Add registry orchestration**

Add `DatabasePlatformRegistry::fullTextSearch()` to resolve the connection and
platform, ask the schema dialect about the declared index, and return the query
dialect result. Document the facade method.

- [ ] **Step 5: Verify native and fallback behavior**

Run the compatibility file on SQLite, MariaDB 10.5, MySQL 8, and PostgreSQL 16.
Expected: native selection only with compatible indexes; all engines satisfy
the same separated-term matching contract and produce descending relevance.

- [ ] **Step 6: Commit**

```bash
git add packages/core/src/Contracts/Database \
  packages/core/src/Support/Database \
  packages/core/src/Facades/CapellDatabase.php \
  packages/core/tests/Unit/Support/Database/DatabaseCompatibilityTest.php
git commit -m "feat(database): add native full text search"
```

### Task 4: Contracts and Final Verification

**Files:**
- Modify if generated: `docs/packages/extension-surface-catalog.json`
- Modify if generated: `docs/packages/extension-surface-catalog.md`

- [ ] **Step 1: Run formatting and static analysis**

```bash
./vendor/bin/pint <changed-php-files> --format agent
composer analyze
```

Expected: Pint passes and PHPStan reports zero errors.

- [ ] **Step 2: Run contract checks**

```bash
composer check:extension-surfaces
composer check:stable-extension-api
composer check:docs-links
composer check:docs-orphans
```

Expected: all checks pass. Regenerate extension surfaces only if the catalogue
reports drift.

- [ ] **Step 3: Re-run focused real-engine proof**

Run the complete database compatibility file on SQLite, MariaDB 10.5, MySQL 8,
and PostgreSQL 16. Remove transient containers and dedicated test databases.

- [ ] **Step 4: Review and push**

Confirm `git diff --check`, a clean worktree, and the exact PR head. Push
`feat/CAP-0042-database-compatibility` to PR #163 and report the stable SHA plus
the caller-facing API.
