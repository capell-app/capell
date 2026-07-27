# SQLite Mixed-Wildcard JSON Search Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make typed SQLite JSON search honour mixed object- and array-wildcard paths without matching values outside the requested path.

**Architecture:** Parse the supported portable wildcard path grammar into ordered SQLite collection paths plus a terminal value path. Compile each collection path into a chained `json_each` join and apply the existing correlated needle predicate only to the terminal `json_extract` value.

**Tech Stack:** PHP 8.3, Laravel database query builder, SQLite JSON1, Pest, PHPStan.

---

### Task 1: Add an executable mixed-wildcard compatibility contract

**Files:**

- Modify: `packages/core/tests/Unit/Support/Database/DatabaseCompatibilityTest.php`

- [ ] **Step 1: Write the failing cross-engine test**

Add a test that uses the active compatibility connection and exact path:

```php
it('searches keyed JSON collections without matching the same needle elsewhere', function (): void {
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
        $dialect->jsonSearch(
            SqlFragment::raw('meta'),
            SqlFragment::raw('needle'),
            $path,
        )->applyWhere($query);

        return $query->exists();
    };

    expect($matches([
        'main' => ['widgets' => [['widget_key' => 'hero-banner']]],
        'metadata' => ['widget_key' => 'unrelated'],
    ], 'hero-banner'))->toBeTrue()
        ->and($matches([
            'main' => ['widgets' => [['widget_key' => 'contact-form']]],
            'metadata' => ['widget_key' => 'hero-banner'],
        ], 'hero-banner'))->toBeFalse();
});
```

- [ ] **Step 2: Run SQLite to verify the regression**

Run:

```bash
./vendor/bin/pest packages/core/tests/Unit/Support/Database/DatabaseCompatibilityTest.php --filter='searches keyed JSON collections' --configuration=phpunit.xml
```

Expected: FAIL because SQLite compiles `$.*.widgets` as a JSON1 path and finds no rows for the valid document.

- [ ] **Step 3: Commit the red test**

```bash
git add packages/core/tests/Unit/Support/Database/DatabaseCompatibilityTest.php
git commit -m "test(database): cover mixed wildcard json search"
```

### Task 2: Parse and compile portable wildcard traversal

**Files:**

- Create: `packages/core/src/Support/Database/QueryDialects/SqliteJsonSearchPath.php`
- Modify: `packages/core/src/Support/Database/QueryDialects/SqliteQueryDialect.php`
- Test: `packages/core/tests/Unit/Support/Database/DatabaseCompatibilityTest.php`

- [ ] **Step 1: Add the immutable path parser**

Create `SqliteJsonSearchPath` with explicit list types:

```php
final readonly class SqliteJsonSearchPath
{
    /**
     * @param list<string> $collectionPaths
     */
    private function __construct(
        public array $collectionPaths,
        public string $valuePath,
    ) {}

    public static function parse(string $path): ?self
    {
        if (! str_starts_with($path, '$')) {
            return null;
        }

        $collectionPaths = [];
        $pendingPath = '$';
        $offset = 1;

        while ($offset < strlen($path)) {
            if (preg_match('/\G(?:\.\*|\[\*\])/', $path, $wildcard, 0, $offset) === 1) {
                $collectionPaths[] = $pendingPath;
                $pendingPath = '$';
                $offset += strlen($wildcard[0]);

                continue;
            }

            if (preg_match('/\G\.([A-Za-z_]\w*)/', $path, $member, 0, $offset) !== 1) {
                return null;
            }

            $pendingPath .= '.' . $member[1];
            $offset += strlen($member[0]);
        }

        if ($collectionPaths === [] || $pendingPath === '$') {
            return null;
        }

        return new self($collectionPaths, $pendingPath);
    }
}
```

- [ ] **Step 2: Compile parsed traversal into chained JSON1 joins**

Replace the SQLite regex branch with parser-driven compilation:

```php
$searchPath = SqliteJsonSearchPath::parse($path);

if ($searchPath !== null) {
    $source = $expression->sql;
    $joins = [];

    foreach ($searchPath->collectionPaths as $level => $collectionPath) {
        $alias = 'capell_json_level_' . $level;
        $joins[] = sprintf('json_each(%s, ?) AS %s', $source, $alias);
        $source = $alias . '.value';
    }

    return new SqlFragment(
        sprintf(
            "EXISTS (SELECT 1 FROM %s WHERE CAST(json_extract(%s, ?) AS TEXT) LIKE ('%%' || CAST(%s AS TEXT) || '%%'))",
            implode(' CROSS JOIN ', $joins),
            $source,
            $needle->sql,
        ),
        [
            ...$expression->bindings,
            ...$searchPath->collectionPaths,
            $searchPath->valuePath,
            ...$needle->bindings,
        ],
    );
}
```

- [ ] **Step 3: Run the focused SQLite contract**

Run:

```bash
./vendor/bin/pest packages/core/tests/Unit/Support/Database/DatabaseCompatibilityTest.php --filter='JSON|json' --configuration=phpunit.xml
```

Expected: PASS, including existing `$[*].data` and `$.widgets[*].widget_key` coverage.

- [ ] **Step 4: Format and statically analyse Core**

Run:

```bash
./vendor/bin/pint packages/core/src/Support/Database/QueryDialects/SqliteJsonSearchPath.php packages/core/src/Support/Database/QueryDialects/SqliteQueryDialect.php packages/core/tests/Unit/Support/Database/DatabaseCompatibilityTest.php
composer analyze
```

Expected: formatting succeeds and PHPStan reports no errors.

- [ ] **Step 5: Commit the implementation**

```bash
git add packages/core/src/Support/Database/QueryDialects/SqliteJsonSearchPath.php packages/core/src/Support/Database/QueryDialects/SqliteQueryDialect.php packages/core/tests/Unit/Support/Database/DatabaseCompatibilityTest.php
git commit -m "fix(database): traverse mixed wildcard json paths"
```

### Task 3: Prove all supported database engines and publish

**Files:**

- Test: `packages/core/tests/Unit/Support/Database/DatabaseCompatibilityTest.php`

- [ ] **Step 1: Run the complete compatibility file on SQLite**

```bash
./vendor/bin/pest packages/core/tests/Unit/Support/Database/DatabaseCompatibilityTest.php --configuration=phpunit.xml
```

Expected: PASS with server-only cases skipped.

- [ ] **Step 2: Run the same file on MariaDB, MySQL 8, and PostgreSQL**

Use a dedicated database in the running MariaDB container:

```bash
docker exec capell-4-mysql-1 mariadb -uroot -ppassword -e \
    'CREATE DATABASE capell_packages_test_cap0042_json CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
DB_CONNECTION=mariadb DB_HOST=127.0.0.1 DB_PORT=13308 \
    DB_DATABASE=capell_packages_test_cap0042_json DB_USERNAME=root DB_PASSWORD=password \
    ./vendor/bin/pest packages/core/tests/Unit/Support/Database/DatabaseCompatibilityTest.php \
    --configuration=phpunit.xml
docker exec capell-4-mysql-1 mariadb -uroot -ppassword -e \
    'DROP DATABASE capell_packages_test_cap0042_json'
```

Use disposable MySQL 8 and PostgreSQL 16 containers with dynamically published
ports, wait for their health commands to succeed, then run the same Pest file:

```bash
docker run --rm -d --name capell-cap0042-json-mysql8 \
    -e MYSQL_ROOT_PASSWORD=password -e MYSQL_DATABASE=capell_packages_test \
    -p 127.0.0.1::3306 mysql:8.0
docker exec capell-cap0042-json-mysql8 mysqladmin ping -uroot -ppassword
CAPELL_JSON_MYSQL_PORT="$(docker port capell-cap0042-json-mysql8 3306/tcp | cut -d: -f2)"
DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT="$CAPELL_JSON_MYSQL_PORT" \
    DB_DATABASE=capell_packages_test DB_USERNAME=root DB_PASSWORD=password \
    ./vendor/bin/pest packages/core/tests/Unit/Support/Database/DatabaseCompatibilityTest.php \
    --configuration=phpunit.xml
docker stop capell-cap0042-json-mysql8

docker run --rm -d --name capell-cap0042-json-postgres16 \
    -e POSTGRES_PASSWORD=password -e POSTGRES_DB=capell_packages_test \
    -p 127.0.0.1::5432 postgres:16-alpine
docker exec capell-cap0042-json-postgres16 \
    pg_isready -U postgres -d capell_packages_test
CAPELL_JSON_POSTGRES_PORT="$(docker port capell-cap0042-json-postgres16 5432/tcp | cut -d: -f2)"
DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT="$CAPELL_JSON_POSTGRES_PORT" \
    DB_DATABASE=capell_packages_test DB_USERNAME=postgres DB_PASSWORD=password \
    ./vendor/bin/pest packages/core/tests/Unit/Support/Database/DatabaseCompatibilityTest.php \
    --configuration=phpunit.xml
docker stop capell-cap0042-json-postgres16
```

Expected: the complete compatibility file passes on each server, including the
new exact-path match and scoped non-match.

- [ ] **Step 3: Reconcile remote changes and push**

```bash
git fetch origin feat/CAP-0042-database-compatibility
git merge origin/feat/CAP-0042-database-compatibility
composer analyze
git push origin feat/CAP-0042-database-compatibility
git rev-parse HEAD
```

Expected: concurrent PR changes are preserved, analysis stays green, and the
exact pushed SHA is reported for PR #163.
