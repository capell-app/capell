# Exact JSON Search and Prefix Full-Text Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add exact correlated JSON string search and restore prefix semantics to native full-text search without changing existing substring JSON search.

**Architecture:** Extend the typed query dialect with a separate `jsonExactSearch` contract implemented through each engine's scoped JSON traversal. Build one escaped prefix query per native full-text request and reuse it for both predicate and relevance, while leaving SQLite's portable fallback unchanged.

**Tech Stack:** PHP 8.3, Laravel database query builder, SQLite JSON1, MySQL/MariaDB full-text Boolean mode, PostgreSQL `tsquery`, Pest, PHPStan, Rector.

---

### Task 1: Add the exact JSON string-search contract

**Files:**

- Modify: `packages/core/src/Contracts/Database/DatabaseQueryDialect.php`
- Modify: `packages/core/src/Support/Database/QueryDialects/MySqlQueryDialect.php`
- Modify: `packages/core/src/Support/Database/QueryDialects/PostgresQueryDialect.php`
- Modify: `packages/core/src/Support/Database/QueryDialects/SqliteQueryDialect.php`
- Create: `packages/core/src/Support/Database/QueryDialects/SqliteJsonTraversal.php`
- Test: `packages/core/tests/Unit/Support/Database/DatabaseCompatibilityTest.php`

- [ ] **Step 1: Write the failing public-seam test**

Add an engine-neutral test using the active compatibility connection:

```php
it('searches exact JSON strings at mixed wildcard paths', function (): void {
    $connection = DB::connection();
    $family = CapellDatabase::for($connection)->family();
    $select = match ($family) {
        DatabaseFamily::MySql => 'CAST(? AS JSON) AS meta',
        DatabaseFamily::PostgreSql => '?::jsonb AS meta',
        DatabaseFamily::MariaDb,
        DatabaseFamily::Sqlite => '? AS meta',
    };
    $dialect = CapellDatabase::for($connection)->queryDialect();
    $path = '$.*.widgets[*].widget_key';

    $matches = function (array $document, string $needle) use ($connection, $dialect, $path, $select): bool {
        $query = $connection->query()->fromSub(
            fn (Builder $query): Builder => $query->selectRaw(
                $select . ', ? AS needle',
                [json_encode($document, JSON_THROW_ON_ERROR), $needle],
            ),
            'documents',
        );
        $dialect->jsonExactSearch(
            SqlFragment::raw('meta'),
            SqlFragment::raw('needle'),
            $path,
        )->applyWhere($query);

        return $query->exists();
    };

    expect($matches([
        'main' => ['widgets' => [['widget_key' => 'hero']]],
    ], 'hero'))->toBeTrue()
        ->and($matches([
            'main' => ['widgets' => [['widget_key' => 'hero-banner']]],
        ], 'hero'))->toBeFalse()
        ->and($matches([
            'main' => ['widgets' => [['widget_key' => 'contact']]],
            'metadata' => ['widget_key' => 'hero'],
        ], 'hero'))->toBeFalse();
});
```

- [ ] **Step 2: Run the test to verify the missing contract**

```bash
./vendor/bin/pest packages/core/tests/Unit/Support/Database/DatabaseCompatibilityTest.php \
    --filter='searches exact JSON strings' --configuration=phpunit.xml
```

Expected: FAIL because `DatabaseQueryDialect::jsonExactSearch` does not exist.

- [ ] **Step 3: Add the typed contract and engine implementations**

Add to `DatabaseQueryDialect`:

```php
public function jsonExactSearch(
    SqlFragment $expression,
    SqlFragment $needle,
    string $path = '$',
): SqlFragment;
```

MySQL and MariaDB compile an exact, escaped string search:

```php
$escapedNeedle = sprintf(
    "REPLACE(REPLACE(REPLACE(CAST(%s AS CHAR), '!', '!!'), '%%', '!%%'), '_', '!_')",
    $needle->sql,
);

return new SqlFragment(
    sprintf(
        "JSON_SEARCH(%s, 'one', %s, '!', ?) IS NOT NULL",
        $expression->sql,
        $escapedNeedle,
    ),
    [...$expression->bindings, ...$needle->bindings, $path],
);
```

PostgreSQL compares only JSON string scalars:

```php
$searchPath = $path === '$' ? '$.**' : $path;

return new SqlFragment(
    sprintf(
        "EXISTS (SELECT 1 FROM jsonb_path_query(%s::jsonb, ?::jsonpath) AS capell_json_exact(value) WHERE jsonb_typeof(capell_json_exact.value) = 'string' AND capell_json_exact.value #>> '{}' = CAST(%s AS TEXT))",
        $expression->sql,
        $needle->sql,
    ),
    [...$expression->bindings, $searchPath, ...$needle->bindings],
);
```

Create a small immutable SQLite traversal result:

```php
final readonly class SqliteJsonTraversal
{
    /**
     * @param  list<mixed>  $bindings
     */
    public function __construct(
        public string $from,
        public string $value,
        public string $valuePath,
        public array $bindings,
    ) {}
}
```

Extract SQLite's parsed traversal compilation into a private method returning
`?SqliteJsonTraversal`. Use the required Rector forms:

```php
$searchPath = SqliteJsonSearchPath::parse($path);

if (! $searchPath instanceof SqliteJsonSearchPath) {
    return null;
}

foreach (array_keys($searchPath->collectionPaths) as $level) {
    $alias = 'capell_json_level_' . $level;
    $joins[] = sprintf('json_each(%s, ?) AS %s', $source, $alias);
    $source = $alias . '.value';
}
```

Reuse that traversal for substring search and add exact string matching:

```php
return new SqlFragment(
    sprintf(
        "EXISTS (SELECT 1 FROM %s CROSS JOIN json_each(%s, ?) AS capell_json_exact_value WHERE capell_json_exact_value.type = 'text' AND capell_json_exact_value.value = CAST(%s AS TEXT))",
        $traversal->from,
        $traversal->value,
        $needle->sql,
    ),
    [...$traversal->bindings, $traversal->valuePath, ...$needle->bindings],
);
```

For non-wildcard paths use scoped `json_tree`, require `type = 'text'`, and
compare `value` to the bound needle.

- [ ] **Step 4: Run the exact JSON slice**

```bash
./vendor/bin/pest packages/core/tests/Unit/Support/Database/DatabaseCompatibilityTest.php \
    --filter='JSON|json' --configuration=phpunit.xml
```

Expected: PASS on SQLite, including existing substring behavior and new exact
match/non-match behaviour.

- [ ] **Step 5: Commit the exact JSON slice**

```bash
git add packages/core/src/Contracts/Database/DatabaseQueryDialect.php \
    packages/core/src/Support/Database/QueryDialects/MySqlQueryDialect.php \
    packages/core/src/Support/Database/QueryDialects/PostgresQueryDialect.php \
    packages/core/src/Support/Database/QueryDialects/SqliteQueryDialect.php \
    packages/core/src/Support/Database/QueryDialects/SqliteJsonTraversal.php \
    packages/core/tests/Unit/Support/Database/DatabaseCompatibilityTest.php
git commit -m "feat(database): add exact json string search"
```

### Task 2: Restore native prefix full-text semantics

**Files:**

- Modify: `packages/core/src/Support/Database/QueryDialects/MySqlQueryDialect.php`
- Modify: `packages/core/src/Support/Database/QueryDialects/PostgresQueryDialect.php`
- Test: `packages/core/tests/Unit/Support/Database/DatabaseCompatibilityTest.php`

- [ ] **Step 1: Write failing native-query and executable search tests**

Update native binding expectations:

```php
'mysql' => [
    new MySqlDatabasePlatform,
    ['title-binding', 'body-binding', '+alpha* +beta*'],
    ['title-binding', 'body-binding', '+alpha* +beta*'],
],
'mariadb' => [
    new MariaDbDatabasePlatform,
    ['title-binding', 'body-binding', '+alpha* +beta*'],
    ['title-binding', 'body-binding', '+alpha* +beta*'],
],
'postgresql' => [
    new PostgresDatabasePlatform,
    ['title-binding', 'body-binding', "'alpha':* & 'beta':*"],
    ['title-binding', 'body-binding', "'alpha':* & 'beta':*"],
],
```

Change the real indexed dataset to prefixes:

```php
$connection->table($table)->insert([
    ['title' => 'portable archive', 'body' => 'portable archive', 'slug' => 'dense'],
    ['title' => 'portable starts here', 'body' => 'architecture ends here', 'slug' => 'separated'],
    ['title' => 'portable only', 'body' => 'without the other term', 'slug' => 'partial'],
]);
```

Search for `port arch` and continue asserting `['dense', 'separated']` in
descending relevance order. This proves prefix matching, AND semantics,
non-match, and ordering through the public registry seam.

- [ ] **Step 2: Run SQLite and one native server to prove the semantic split**

```bash
./vendor/bin/pest packages/core/tests/Unit/Support/Database/DatabaseCompatibilityTest.php \
    --filter='native full text|selects native full text' --configuration=phpunit.xml
```

Expected on SQLite: binding-generation assertions FAIL because native fragments
still contain whole-term queries; the indexed fallback behaviour passes.

Run the same filter on MariaDB. Expected: the indexed prefix behaviour FAILS
because `+"port" +"arch"` does not match longer lexemes.

- [ ] **Step 3: Build one escaped prefix query per native dialect**

In `MySqlQueryDialect`, replace quoted whole terms with required suffix-wildcard
terms and reuse the Boolean-mode query for relevance:

```php
$booleanQuery = implode(' ', array_map(
    static fn (string $term): string => '+' . self::escapeBooleanTerm($term) . '*',
    $terms,
));
```

`escapeBooleanTerm` must escape backslashes and Boolean operator characters
before adding Capell's controlled leading `+` and trailing `*`. Both returned
fragments use `AGAINST (? IN BOOLEAN MODE)` and bind `$booleanQuery`.

```php
private static function escapeBooleanTerm(string $term): string
{
    return str_replace(
        ['\\', '+', '-', '>', '<', '(', ')', '~', '*', '"', '@'],
        ['\\\\', '\+', '\-', '\>', '\<', '\(', '\)', '\~', '\*', '\"', '\@'],
        $term,
    );
}
```

In `PostgresQueryDialect`, build a bound prefix `tsquery`:

```php
$prefixQuery = implode(' & ', array_map(
    static fn (string $term): string => "'" . str_replace(
        ['\\', "'"],
        ['\\\\', "''"],
        $term,
    ) . "':*",
    $terms,
));
$queryExpression = "to_tsquery('simple', ?)";
```

Bind `$prefixQuery` to both predicate and `ts_rank_cd` relevance.

- [ ] **Step 4: Run the focused full-text slice**

```bash
./vendor/bin/pest packages/core/tests/Unit/Support/Database/DatabaseCompatibilityTest.php \
    --filter='full text' --configuration=phpunit.xml
```

Expected: all SQLite fallback and typed native-fragment tests pass.

- [ ] **Step 5: Commit the full-text slice**

```bash
git add packages/core/src/Support/Database/QueryDialects/MySqlQueryDialect.php \
    packages/core/src/Support/Database/QueryDialects/PostgresQueryDialect.php \
    packages/core/tests/Unit/Support/Database/DatabaseCompatibilityTest.php
git commit -m "fix(database): preserve native full text prefixes"
```

### Task 3: Cache native-index compatibility per request

**Files:**

- Modify: `packages/core/src/Support/Database/DatabasePlatformRegistry.php`
- Modify: `packages/core/src/Providers/CapellServiceProvider.php`
- Modify: `packages/core/src/Facades/CapellDatabase.php`
- Modify: `docs/operations/octane.md`
- Test: `packages/core/tests/Unit/Support/Database/DatabaseCompatibilityTest.php`

- [ ] **Step 1: Write the failing repeat-call and invalidation test**

At the public registry seam, use a schema dialect test double whose
`hasCompatibleFullTextIndex()` method counts calls. Call `fullTextSearch()`
twice for the same connection and definition, invalidate that definition, then
call it again:

```php
$schemaDialect->shouldReceive('hasCompatibleFullTextIndex')
    ->twice()
    ->with($index, $connection)
    ->andReturnTrue();

$registry->fullTextSearch($connection, $index, $expressions, 'port');
$registry->fullTextSearch($connection, $index, $expressions, 'port');
$registry->forgetFullTextIndexCompatibility($connection, $index);
$registry->fullTextSearch($connection, $index, $expressions, 'port');
```

Also prove an equivalent connection object and a new scoped registry reuse the
same inspection, changed connection configuration or database names do not,
the LRU bound evicts the oldest entry, and
`flushFullTextIndexCompatibility()` forces the next call to inspect again.

- [ ] **Step 2: Run the cache test to prove repeated inspection**

```bash
./vendor/bin/pest packages/core/tests/Unit/Support/Database/DatabaseCompatibilityTest.php \
    --filter='caches full text index compatibility' --configuration=phpunit.xml
```

Expected: FAIL because the registry performs one metadata inspection per call
and has no invalidation API.

- [ ] **Step 3: Add the bounded process-lived compatibility cache**

Create `FullTextIndexCompatibilityCache` with a 256-entry LRU bound. Key each
entry by a stable hash of non-secret logical connection metadata and the
complete index definition. The connection metadata includes connection name,
driver, database, host, port, Unix socket, table prefix, and read/write hosts;
it excludes credentials and URLs.

```php
$this->app->singleton(FullTextIndexCompatibilityCache::class);
```

Inject the singleton cache into the scoped `DatabasePlatformRegistry`. Cache
both true and false results with `array_key_exists()`, update recency on hits,
and evict the least-recently-used entry when the configured maximum is
exceeded.

Expose:

```php
public function forgetFullTextIndexCompatibility(
    Connection $connection,
    ?DatabaseIndexDefinition $index = null,
): void;

public function flushFullTextIndexCompatibility(): void;
```

The first method removes one index fingerprint or every entry for the supplied
logical connection. The second clears the bounded process cache.

- [ ] **Step 4: Bind the lifetimes explicitly**

```php
$this->app->singleton(FullTextIndexCompatibilityCache::class);
$this->app->scoped(
    DatabasePlatformRegistry::class,
    fn ($app): DatabasePlatformRegistry => new DatabasePlatformRegistry(
        [...],
        $app->make(FullTextIndexCompatibilityCache::class),
    ),
);
```

Add both invalidation methods to the `CapellDatabase` facade PHPDoc. Add
the cache to the executable singleton lifetime inventory as a bounded,
explicitly invalidated process cache. Document that the registry is scoped but
the compatibility metadata deliberately survives request boundaries, and that
long-running workers must restart after migrations.

Update the real index-creation compatibility test to call:

```php
CapellDatabase::forgetFullTextIndexCompatibility($connection, $index);
```

after executing the DDL and before asking the registry to select native mode.

- [ ] **Step 5: Run the cache and indexed-search tests**

```bash
./vendor/bin/pest packages/core/tests/Unit/Support/Database/DatabaseCompatibilityTest.php \
    --filter='caches full text index compatibility|selects native full text' \
    --configuration=phpunit.xml
```

Expected: PASS with one metadata inspection before invalidation and one after.

- [ ] **Step 6: Commit the cache slice**

```bash
git add packages/core/src/Support/Database/DatabasePlatformRegistry.php \
    packages/core/src/Providers/CapellServiceProvider.php \
    packages/core/src/Facades/CapellDatabase.php \
    packages/core/tests/Unit/Support/Database/DatabaseCompatibilityTest.php \
    docs/operations/octane.md
git commit -m "perf(database): cache full text index compatibility"
```

### Task 4: Add typed weighted full-text expressions

**Files:**

- Create: `packages/core/src/Data/Database/DatabaseSearchExpression.php`
- Modify: `packages/core/src/Contracts/Database/DatabaseQueryDialect.php`
- Modify: `packages/core/src/Support/Database/QueryDialects/AbstractQueryDialect.php`
- Modify: `packages/core/src/Support/Database/QueryDialects/MySqlQueryDialect.php`
- Modify: `packages/core/src/Support/Database/QueryDialects/PostgresQueryDialect.php`
- Modify: `packages/core/src/Support/Database/DatabasePlatformRegistry.php`
- Modify: `packages/core/src/Facades/CapellDatabase.php`
- Modify: `packages/core/src/Actions/Extensions/BuildExtensionSurfaceCatalogAction.php`
- Modify: `packages/core/tests/Unit/Actions/Extensions/BuildExtensionSurfaceCatalogActionTest.php`
- Modify: `packages/core/tests/Unit/Support/Database/DatabaseCompatibilityTest.php`
- Generate: `docs/packages/extension-surface-catalog.json`
- Generate: `docs/packages/extension-surface-catalog.md`

- [ ] **Step 1: Write failing validation and weighted ranking tests**

Construct weighted expressions explicitly:

```php
$expressions = [
    new DatabaseSearchExpression(SqlFragment::raw($grammar->wrap('title')), 5.0),
    new DatabaseSearchExpression(SqlFragment::raw($grammar->wrap('body')), 1.0),
];
```

Use indexed rows where both prefixes occur in the stronger title, are separated
across title and body, occur only in the weaker body, or miss one term. Search
for `port arch` and expect:

```php
['strong-title', 'separated', 'weak-body']
```

The partial row must not match. Add constructor tests proving `0.0`, `-1.0`,
`INF`, and `NAN` throw `InvalidArgumentException`.

- [ ] **Step 2: Run weighted tests to prove the missing type and ranking**

```bash
./vendor/bin/pest packages/core/tests/Unit/Support/Database/DatabaseCompatibilityTest.php \
    --filter='weighted|full text' --configuration=phpunit.xml
```

Expected: FAIL because `DatabaseSearchExpression` does not exist and current
relevance has no weight.

- [ ] **Step 3: Add the strict DTO and evolve typed contracts**

Create:

```php
final readonly class DatabaseSearchExpression
{
    public function __construct(
        public SqlFragment $expression,
        public float $weight = 1.0,
    ) {
        throw_unless(
            is_finite($weight) && $weight > 0,
            InvalidArgumentException::class,
            'Database search expression weights must be positive and finite.',
        );
    }
}
```

Change every `fullTextSearch` list annotation and implementation from
`SqlFragment` to `DatabaseSearchExpression`. Update all Core callers and tests
to construct the DTO explicitly.

- [ ] **Step 4: Apply portable weighted relevance in every mode**

In `AbstractQueryDialect`, keep predicate generation unchanged apart from
reading `$searchExpression->expression`. Build relevance as:

```php
$relevanceSql[] = sprintf('CASE WHEN %s THEN ? ELSE 0 END', $match);
$relevanceBindings = [
    ...$relevanceBindings,
    ...$expression->bindings,
    $pattern,
    $searchExpression->weight,
];
```

MySQL, MariaDB, and PostgreSQL continue returning their native combined prefix
predicate, but return `$fallback->relevance` for deterministic weighted
coverage. Native mode remains `true`.

- [ ] **Step 5: Register and generate the experimental DTO surface**

Add `core.dto.database-search-expression` to
`BuildExtensionSurfaceCatalogAction` with experimental stability and update its
direct unit expectation. Generate contracts:

```bash
php scripts/build-extension-surface-catalog.php
composer check:extension-surfaces
```

Expected: generated JSON and Markdown include the DTO and the executable
catalogue check passes.

- [ ] **Step 6: Run weighted full-text tests and commit**

```bash
./vendor/bin/pest packages/core/tests/Unit/Support/Database/DatabaseCompatibilityTest.php \
    --filter='weighted|full text' --configuration=phpunit.xml
./vendor/bin/pest packages/core/tests/Unit/Actions/Extensions/BuildExtensionSurfaceCatalogActionTest.php \
    --configuration=phpunit.xml
```

Expected: validation, binding order, prefix AND, weighted ordering, and
catalogue tests pass.

```bash
git add packages/core/src/Data/Database/DatabaseSearchExpression.php \
    packages/core/src/Contracts/Database/DatabaseQueryDialect.php \
    packages/core/src/Support/Database/QueryDialects \
    packages/core/src/Support/Database/DatabasePlatformRegistry.php \
    packages/core/src/Facades/CapellDatabase.php \
    packages/core/src/Actions/Extensions/BuildExtensionSurfaceCatalogAction.php \
    packages/core/tests/Unit/Actions/Extensions/BuildExtensionSurfaceCatalogActionTest.php \
    packages/core/tests/Unit/Support/Database/DatabaseCompatibilityTest.php \
    docs/packages/extension-surface-catalog.json \
    docs/packages/extension-surface-catalog.md
git commit -m "feat(database): add weighted search expressions"
```

### Task 5: Verify every engine and publish PR #166

**Files:**

- Verify: `packages/core/src/Contracts/Database/DatabaseQueryDialect.php`
- Verify: `packages/core/src/Support/Database/QueryDialects/*.php`
- Verify: `packages/core/tests/Unit/Support/Database/DatabaseCompatibilityTest.php`

- [ ] **Step 1: Run deterministic quality checks**

```bash
./vendor/bin/pint packages/core/src/Contracts/Database/DatabaseQueryDialect.php \
    packages/core/src/Support/Database/QueryDialects \
    packages/core/tests/Unit/Support/Database/DatabaseCompatibilityTest.php
composer rector:check
composer analyze
```

Expected: Pint succeeds, Rector reports no drift, and PHPStan reports zero
errors.

- [ ] **Step 2: Run the full compatibility file on SQLite**

```bash
./vendor/bin/pest packages/core/tests/Unit/Support/Database/DatabaseCompatibilityTest.php \
    --configuration=phpunit.xml
```

Expected: all SQLite-capable tests pass and server-only tests skip.

- [ ] **Step 3: Run the full file on MariaDB, MySQL 8, and PostgreSQL 16**

Use a dedicated database in the running MariaDB container:

```bash
docker exec capell-4-mysql-1 mariadb -uroot -ppassword -e \
    'CREATE DATABASE capell_packages_test_cap0042_semantics CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
DB_CONNECTION=mariadb DB_HOST=127.0.0.1 DB_PORT=13308 \
    DB_DATABASE=capell_packages_test_cap0042_semantics DB_USERNAME=root DB_PASSWORD=password \
    ./vendor/bin/pest packages/core/tests/Unit/Support/Database/DatabaseCompatibilityTest.php \
    --configuration=phpunit.xml
docker exec capell-4-mysql-1 mariadb -uroot -ppassword -e \
    'DROP DATABASE capell_packages_test_cap0042_semantics'
```

Use disposable MySQL 8 and PostgreSQL 16 containers:

```bash
docker run --rm -d --name capell-cap0042-semantics-mysql8 \
    -e MYSQL_ROOT_PASSWORD=password -e MYSQL_DATABASE=capell_packages_test \
    -p 127.0.0.1::3306 mysql:8.0
docker exec capell-cap0042-semantics-mysql8 mysqladmin ping -uroot -ppassword
CAPELL_SEMANTICS_MYSQL_PORT="$(docker port capell-cap0042-semantics-mysql8 3306/tcp | cut -d: -f2)"
DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT="$CAPELL_SEMANTICS_MYSQL_PORT" \
    DB_DATABASE=capell_packages_test DB_USERNAME=root DB_PASSWORD=password \
    ./vendor/bin/pest packages/core/tests/Unit/Support/Database/DatabaseCompatibilityTest.php \
    --configuration=phpunit.xml
docker stop capell-cap0042-semantics-mysql8

docker run --rm -d --name capell-cap0042-semantics-postgres16 \
    -e POSTGRES_PASSWORD=password -e POSTGRES_DB=capell_packages_test \
    -p 127.0.0.1::5432 postgres:16-alpine
docker exec capell-cap0042-semantics-postgres16 \
    pg_isready -U postgres -d capell_packages_test
CAPELL_SEMANTICS_POSTGRES_PORT="$(docker port capell-cap0042-semantics-postgres16 5432/tcp | cut -d: -f2)"
DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT="$CAPELL_SEMANTICS_POSTGRES_PORT" \
    DB_DATABASE=capell_packages_test DB_USERNAME=postgres DB_PASSWORD=password \
    ./vendor/bin/pest packages/core/tests/Unit/Support/Database/DatabaseCompatibilityTest.php \
    --configuration=phpunit.xml
docker stop capell-cap0042-semantics-postgres16
```

Expected: all compatibility tests pass on every server, including exact JSON
and indexed prefix full-text behaviour. Remove disposable databases and
containers afterward.

- [ ] **Step 4: Preserve remote changes and update the open PR**

```bash
git fetch origin fix/CAP-0042-sqlite-json-wildcards
git merge origin/fix/CAP-0042-sqlite-json-wildcards
composer rector:check
composer analyze
git push origin fix/CAP-0042-sqlite-json-wildcards
git rev-parse HEAD
gh pr view 166 --repo capell-app/capell \
    --json state,isDraft,headRefOid,url,statusCheckRollup
```

Expected: the branch is clean, PR #166 remains ready, and its exact head SHA is
reported. If PR #166 merged during implementation, create a clean branch from
the current `origin/main`, cherry-pick only these focused commits, verify again,
and open a ready follow-up PR.
