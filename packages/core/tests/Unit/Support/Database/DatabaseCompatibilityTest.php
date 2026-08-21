<?php

declare(strict_types=1);

use Capell\Core\Contracts\Database\DatabasePlatform;
use Capell\Core\Data\Database\DatabaseIndexDefinition;
use Capell\Core\Data\Database\SqlFragment;
use Capell\Core\Enums\Database\DatabaseCapability;
use Capell\Core\Enums\Database\DatabaseDateOperation;
use Capell\Core\Enums\Database\DatabaseFamily;
use Capell\Core\Enums\Database\DatabaseProvisioningResult;
use Capell\Core\Exceptions\UnsupportedDatabaseDriver;
use Capell\Core\Facades\CapellDatabase;
use Capell\Core\Support\Database\DatabasePlatformRegistry;
use Capell\Core\Support\Database\Platforms\MariaDbDatabasePlatform;
use Capell\Core\Support\Database\Platforms\MySqlDatabasePlatform;
use Capell\Core\Support\Database\Platforms\PostgresDatabasePlatform;
use Capell\Core\Support\Database\Platforms\SqliteDatabasePlatform;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

it('resolves every supported database driver through one registry seam', function (): void {
    $mysql = new MySqlDatabasePlatform;
    $mariaDb = new MariaDbDatabasePlatform;
    $sqlite = new SqliteDatabasePlatform;
    $postgres = new PostgresDatabasePlatform;
    $registry = new DatabasePlatformRegistry([$mysql, $mariaDb, $sqlite, $postgres]);

    expect($registry->forDriver('mysql'))->toBe($mysql)
        ->and($registry->forDriver('mariadb'))->toBe($mariaDb)
        ->and($registry->forDriver('sqlite'))->toBe($sqlite)
        ->and($registry->forDriver('pgsql'))->toBe($postgres)
        ->and($registry->forDriver('postgresql'))->toBe($postgres)
        ->and($registry->for('mysql'))->toBe($mysql);
});

it('resolves a MariaDB server through an explicitly named mysql connection', function (): void {
    $pdo = Mockery::mock(PDO::class);
    $pdo->shouldReceive('getAttribute')
        ->once()
        ->with(PDO::ATTR_SERVER_VERSION)
        ->andReturn('10.11.8-MariaDB');
    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('getDriverName')->once()->andReturn('mysql');
    $connection->shouldReceive('getPdo')->once()->andReturn($pdo);
    $connections = Mockery::mock(DatabaseManager::class);
    $connections->shouldReceive('connection')->once()->with('mysql')->andReturn($connection);
    $mysql = new MySqlDatabasePlatform;
    $mariaDb = new MariaDbDatabasePlatform;
    $registry = new DatabasePlatformRegistry([$mysql, $mariaDb], connections: $connections);

    expect($registry->forDriver('mysql'))->toBe($mysql)
        ->and($registry->forConnection('mysql'))->toBe($mariaDb);
});

it('resolves configured connections and rejects duplicates and unknown drivers', function (): void {
    $sqlite = new SqliteDatabasePlatform;
    $registry = new DatabasePlatformRegistry([$sqlite, new PostgresDatabasePlatform]);
    $connection = new SQLiteConnection(new PDO('sqlite::memory:'), ':memory:', '', ['driver' => 'sqlite']);

    expect($registry->for($connection))->toBe($sqlite)
        ->and(fn (): DatabasePlatformRegistry => $registry->register(new SqliteDatabasePlatform))
        ->toThrow(LogicException::class, 'Database driver [sqlite] is already registered.')
        ->and(fn (): DatabasePlatform => $registry->for('sqlsrv'))
        ->toThrow(UnsupportedDatabaseDriver::class, 'Unsupported database driver [sqlsrv].')
        ->and(fn (): DatabasePlatform => $registry->for('capell_missing_driver'))
        ->toThrow(UnsupportedDatabaseDriver::class, 'Unsupported database driver [capell_missing_driver].');
});

it('declares platform family metadata and optional provisioners', function (): void {
    $mysql = new MySqlDatabasePlatform;
    $mariaDb = new MariaDbDatabasePlatform;
    $sqlite = new SqliteDatabasePlatform;
    $postgres = new PostgresDatabasePlatform;

    expect($mysql->family())->toBe(DatabaseFamily::MySql)
        ->and($mysql->phpExtension())->toBe('pdo_mysql')
        ->and($mysql->provisioner())->not->toBeNull()
        ->and($mariaDb->family())->toBe(DatabaseFamily::MariaDb)
        ->and($mariaDb->phpExtension())->toBe('pdo_mysql')
        ->and($mariaDb->provisioner())->not->toBeNull()
        ->and($sqlite->family())->toBe(DatabaseFamily::Sqlite)
        ->and($sqlite->phpExtension())->toBe('pdo_sqlite')
        ->and($sqlite->provisioner())->not->toBeNull()
        ->and($postgres->family())->toBe(DatabaseFamily::PostgreSql)
        ->and($postgres->phpExtension())->toBe('pdo_pgsql')
        ->and($postgres->provisioner())->not->toBeNull();
});

it('builds typed query expressions for every supported database family', function (
    DatabasePlatform $platform,
    array $expected,
): void {
    $dialect = $platform->queryDialect();
    $column = SqlFragment::raw('pages.name');

    $jsonContainsBindings = in_array($platform->family(), [DatabaseFamily::MySql, DatabaseFamily::MariaDb], true)
        ? ['"featured"', '$.tags']
        : ['$.tags', '"featured"'];
    $jsonSearchBindings = match ($platform->family()) {
        DatabaseFamily::MySql,
        DatabaseFamily::MariaDb => ['needle', '$[*].data'],
        DatabaseFamily::Sqlite => ['$', '$.data', 'needle'],
        DatabaseFamily::PostgreSql => ['$[*].data', 'needle'],
    };

    expect($dialect->concatenate($column, SqlFragment::value(' / '), SqlFragment::raw('pages.slug')))
        ->toEqual(new SqlFragment($expected['concat'], [' / ']))
        ->and($dialect->trimTrailingSlash(SqlFragment::raw('pages.url')))
        ->toEqual(new SqlFragment($expected['trim']))
        ->and($dialect->textPosition($column, 'ell', true))
        ->toEqual(new SqlFragment($expected['position'], ['ell']))
        ->and($dialect->textRelevance($column, 'Capell'))
        ->toEqual(new SqlFragment($expected['relevance'], ['capell', 'capell%', '%capell%', 'capell']))
        ->and($dialect->date(DatabaseDateOperation::Year, SqlFragment::raw('created_at')))
        ->toEqual(new SqlFragment($expected['year']))
        ->and($dialect->date(DatabaseDateOperation::HourLabel, SqlFragment::raw('created_at')))
        ->toEqual(new SqlFragment($expected['hour']))
        ->and($dialect->elapsedSeconds(SqlFragment::raw('started_at'), SqlFragment::raw('finished_at')))
        ->toEqual(new SqlFragment($expected['elapsed']))
        ->and($dialect->jsonExtract(SqlFragment::raw('meta'), '$.page_id'))
        ->toEqual(new SqlFragment($expected['json_extract'], ['$.page_id']))
        ->and($dialect->jsonContains(SqlFragment::raw('meta'), 'featured', '$.tags'))
        ->toEqual(new SqlFragment($expected['json_contains'], $jsonContainsBindings))
        ->and($dialect->jsonSearch(SqlFragment::raw('meta'), SqlFragment::value('needle'), '$[*].data'))
        ->toEqual(new SqlFragment($expected['json_search'], $jsonSearchBindings));
})->with([
    'mysql' => [
        new MySqlDatabasePlatform,
        [
            'concat' => 'CONCAT(pages.name, ?, pages.slug)',
            'trim' => "TRIM(TRAILING '/' FROM pages.url)",
            'position' => 'INSTR(LOWER(pages.name), ?)',
            'relevance' => 'CASE WHEN LOWER(pages.name) = ? THEN 0 WHEN LOWER(pages.name) LIKE ? THEN 1 WHEN LOWER(pages.name) LIKE ? THEN 2 ELSE 3 END + INSTR(LOWER(pages.name), ?) / 1000',
            'year' => 'YEAR(created_at)',
            'hour' => "DATE_FORMAT(created_at, '%H:00')",
            'elapsed' => 'TIMESTAMPDIFF(SECOND, started_at, finished_at)',
            'json_extract' => 'JSON_EXTRACT(meta, ?)',
            'json_contains' => 'JSON_CONTAINS(meta, ?, ?)',
            'json_search' => "JSON_SEARCH(meta, 'one', CONCAT('%', ?, '%'), NULL, ?) IS NOT NULL",
        ],
    ],
    'mariadb' => [
        new MariaDbDatabasePlatform,
        [
            'concat' => 'CONCAT(pages.name, ?, pages.slug)',
            'trim' => "TRIM(TRAILING '/' FROM pages.url)",
            'position' => 'INSTR(LOWER(pages.name), ?)',
            'relevance' => 'CASE WHEN LOWER(pages.name) = ? THEN 0 WHEN LOWER(pages.name) LIKE ? THEN 1 WHEN LOWER(pages.name) LIKE ? THEN 2 ELSE 3 END + INSTR(LOWER(pages.name), ?) / 1000',
            'year' => 'YEAR(created_at)',
            'hour' => "DATE_FORMAT(created_at, '%H:00')",
            'elapsed' => 'TIMESTAMPDIFF(SECOND, started_at, finished_at)',
            'json_extract' => 'JSON_EXTRACT(meta, ?)',
            'json_contains' => 'JSON_CONTAINS(meta, ?, ?)',
            'json_search' => "JSON_SEARCH(meta, 'one', CONCAT('%', ?, '%'), NULL, ?) IS NOT NULL",
        ],
    ],
    'sqlite' => [
        new SqliteDatabasePlatform,
        [
            'concat' => 'pages.name || ? || pages.slug',
            'trim' => "RTRIM(pages.url, '/')",
            'position' => 'INSTR(LOWER(pages.name), ?)',
            'relevance' => 'CASE WHEN LOWER(pages.name) = ? THEN 0 WHEN LOWER(pages.name) LIKE ? THEN 1 WHEN LOWER(pages.name) LIKE ? THEN 2 ELSE 3 END + INSTR(LOWER(pages.name), ?) / 1000.0',
            'year' => "CAST(strftime('%Y', created_at) AS INTEGER)",
            'hour' => "strftime('%H:00', created_at)",
            'elapsed' => 'CAST(ROUND((julianday(finished_at) - julianday(started_at)) * 86400) AS INTEGER)',
            'json_extract' => 'json_extract(meta, ?)',
            'json_contains' => "EXISTS (SELECT 1 FROM json_each(meta, ?) AS capell_json_item CROSS JOIN (SELECT json(?) AS value) AS capell_json_target WHERE CASE WHEN capell_json_item.type IN ('integer', 'real') AND json_type(capell_json_target.value) IN ('integer', 'real') THEN CAST(capell_json_item.value AS NUMERIC) = CAST(json_extract(capell_json_target.value, '$') AS NUMERIC) WHEN capell_json_item.type = 'true' THEN json_type(capell_json_target.value) = 'true' WHEN capell_json_item.type = 'false' THEN json_type(capell_json_target.value) = 'false' WHEN capell_json_item.type IN ('array', 'object') THEN json(capell_json_item.value) = json(capell_json_target.value) ELSE json_quote(capell_json_item.value) = capell_json_target.value END)",
            'json_search' => "EXISTS (SELECT 1 FROM json_each(meta, ?) AS capell_json_level_0 WHERE CAST(json_extract(capell_json_level_0.value, ?) AS TEXT) LIKE ('%' || CAST(? AS TEXT) || '%'))",
        ],
    ],
    'postgresql' => [
        new PostgresDatabasePlatform,
        [
            'concat' => 'pages.name || ? || pages.slug',
            'trim' => "RTRIM(pages.url, '/')",
            'position' => 'STRPOS(LOWER(pages.name), ?)',
            'relevance' => 'CASE WHEN LOWER(pages.name) = ? THEN 0 WHEN LOWER(pages.name) LIKE ? THEN 1 WHEN LOWER(pages.name) LIKE ? THEN 2 ELSE 3 END + STRPOS(LOWER(pages.name), ?) / 1000.0',
            'year' => 'EXTRACT(YEAR FROM created_at)::INTEGER',
            'hour' => "TO_CHAR(created_at, 'HH24:00')",
            'elapsed' => 'EXTRACT(EPOCH FROM (finished_at - started_at))::INTEGER',
            'json_extract' => 'jsonb_path_query_first(meta::jsonb, ?::jsonpath)',
            'json_contains' => "EXISTS (SELECT 1 FROM jsonb_path_query(meta::jsonb, ?::jsonpath) AS capell_json_contains(value) CROSS JOIN (SELECT ?::jsonb AS candidate) AS capell_json_target WHERE capell_json_contains.value = capell_json_target.candidate OR capell_json_contains.value @> capell_json_target.candidate OR EXISTS (SELECT 1 FROM jsonb_array_elements(CASE WHEN jsonb_typeof(capell_json_contains.value) = 'array' THEN capell_json_contains.value ELSE jsonb_build_array(capell_json_contains.value) END) AS capell_json_element(value) WHERE capell_json_element.value = capell_json_target.candidate))",
            'json_search' => "EXISTS (SELECT 1 FROM jsonb_path_query(meta::jsonb, ?::jsonpath) AS capell_json_search(value) WHERE capell_json_search.value #>> '{}' ILIKE ('%' || CAST(? AS TEXT) || '%'))",
        ],
    ],
]);

it('matches typed JSON values without coercing numbers or booleans to strings', function (): void {
    $connection = DB::connection();
    $family = CapellDatabase::for($connection)->family();
    $select = match ($family) {
        DatabaseFamily::MySql => 'CAST(? AS JSON) AS meta',
        DatabaseFamily::PostgreSql => '?::jsonb AS meta',
        DatabaseFamily::MariaDb,
        DatabaseFamily::Sqlite => '? AS meta',
    };
    $document = json_encode([
        'values' => ['featured', 42, true, [1, 2]],
    ], JSON_THROW_ON_ERROR);
    $dialect = CapellDatabase::for($connection)->queryDialect();

    foreach (['featured', 42, true, [1, 2]] as $value) {
        $query = $connection->query()->fromSub(
            fn (Builder $query): Builder => $query->selectRaw($select, [$document]),
            'documents',
        );
        $dialect->jsonContains(SqlFragment::raw('meta'), $value, '$.values')
            ->applyWhere($query);

        expect($query->exists())->toBeTrue();
    }

    foreach (['missing', 43, false, [2, 3]] as $value) {
        $query = $connection->query()->fromSub(
            fn (Builder $query): Builder => $query->selectRaw($select, [$document]),
            'documents',
        );
        $dialect->jsonContains(SqlFragment::raw('meta'), $value, '$.values')
            ->applyWhere($query);

        expect($query->exists())->toBeFalse();
    }
});

it('returns exact elapsed seconds for SQLite timestamps', function (
    string $start,
    string $end,
    int $expected,
): void {
    $connection = new SQLiteConnection(new PDO('sqlite::memory:'), ':memory:', '', ['driver' => 'sqlite']);
    $fragment = (new SqliteDatabasePlatform)->queryDialect()->elapsedSeconds(
        SqlFragment::value($start),
        SqlFragment::value($end),
    );

    $elapsed = $connection->query()
        ->selectRaw($fragment->sql . ' AS elapsed_seconds', $fragment->bindings)
        ->value('elapsed_seconds');

    expect($elapsed)->toBe($expected);
})->with([
    'two minutes' => ['2026-07-26 12:00:00', '2026-07-26 12:02:00', 120],
    'one minute' => ['2026-07-26 12:00:00', '2026-07-26 12:01:00', 60],
    'one second' => ['2026-07-26 12:00:00', '2026-07-26 12:00:01', 1],
]);

it('treats equivalent integer and decimal JSON values as equal', function (): void {
    $connection = DB::connection();
    $family = CapellDatabase::for($connection)->family();
    $select = match ($family) {
        DatabaseFamily::MySql => 'CAST(? AS JSON) AS meta',
        DatabaseFamily::PostgreSql => '?::jsonb AS meta',
        DatabaseFamily::MariaDb,
        DatabaseFamily::Sqlite => '? AS meta',
    };
    $dialect = CapellDatabase::for($connection)->queryDialect();

    foreach ([[1, 1.0], [1.0, 1]] as [$stored, $candidate]) {
        $document = json_encode(['values' => [$stored]], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
        $query = $connection->query()->fromSub(
            fn (Builder $query): Builder => $query->selectRaw($select, [$document]),
            'documents',
        );
        $dialect->jsonContains(SqlFragment::raw('meta'), $candidate, '$.values')
            ->applyWhere($query);

        expect($query->exists())->toBeTrue();
    }
});

it('repeats bound expression bindings in SQL placeholder order for relevance', function (): void {
    $connection = DB::connection();
    $rows = $connection->query()->selectRaw('? AS name', ['Other'])
        ->unionAll($connection->query()->selectRaw('? AS name', ['Capell']));
    $query = $connection->query()->fromSub($rows, 'pages')->select('name');
    $dialect = CapellDatabase::for($connection)->queryDialect();
    $relevance = $dialect->textRelevance(
        $dialect->concatenate(SqlFragment::value(''), SqlFragment::raw('name')),
        'Capell',
    );

    $relevance->applyOrder($query);

    expect($relevance->bindings)->toHaveCount(8)
        ->and($query->pluck('name')->all())->toBe(['Capell', 'Other']);
});

it('searches SQLite JSON array properties with wildcard paths', function (): void {
    $connection = new SQLiteConnection(new PDO('sqlite::memory:'), ':memory:', '', ['driver' => 'sqlite']);
    $matching = $connection->query()->fromSub(
        fn (Builder $query): Builder => $query->selectRaw(
            '? AS meta',
            [json_encode([['data' => 'A Capell needle appears here']], JSON_THROW_ON_ERROR)],
        ),
        'documents',
    );
    $notMatching = clone $matching;
    $dialect = (new SqliteDatabasePlatform)->queryDialect();

    $dialect->jsonSearch(SqlFragment::raw('meta'), SqlFragment::value('needle'), '$[*].data')
        ->applyWhere($matching);
    $dialect->jsonSearch(SqlFragment::raw('meta'), SqlFragment::value('absent'), '$[*].data')
        ->applyWhere($notMatching);

    expect($matching->exists())->toBeTrue()
        ->and($notMatching->exists())->toBeFalse();
});

it('searches JSON values using a correlated column needle', function (): void {
    $connection = DB::connection();
    $family = CapellDatabase::for($connection)->family();
    $select = match ($family) {
        DatabaseFamily::MySql => 'CAST(? AS JSON) AS meta',
        DatabaseFamily::PostgreSql => '?::jsonb AS meta',
        DatabaseFamily::MariaDb,
        DatabaseFamily::Sqlite => '? AS meta',
    };
    $document = json_encode([
        'widgets' => [
            ['widget_key' => 'hero-banner'],
            ['widget_key' => 'contact-form'],
        ],
    ], JSON_THROW_ON_ERROR);
    $dialect = CapellDatabase::for($connection)->queryDialect();
    $boundSearch = $dialect->jsonSearch(
        new SqlFragment('?', ['document-binding']),
        new SqlFragment('?', ['needle-binding']),
        '$.widgets[*].widget_key',
    );
    $expectedBindings = match ($family) {
        DatabaseFamily::MySql,
        DatabaseFamily::MariaDb => ['document-binding', 'needle-binding', '$.widgets[*].widget_key'],
        DatabaseFamily::Sqlite => ['document-binding', '$.widgets', '$.widget_key', 'needle-binding'],
        DatabaseFamily::PostgreSql => ['document-binding', '$.widgets[*].widget_key', 'needle-binding'],
    };

    $matches = function (string $needle) use ($connection, $dialect, $document, $select): bool {
        $query = $connection->query()->fromSub(
            fn (Builder $query): Builder => $query->selectRaw(
                $select . ', ? AS needle',
                [$document, $needle],
            ),
            'documents',
        );
        $dialect->jsonSearch(
            SqlFragment::raw('meta'),
            SqlFragment::raw('needle'),
            '$.widgets[*].widget_key',
        )->applyWhere($query);

        return $query->exists();
    };

    expect($matches('hero-banner'))->toBeTrue()
        ->and($matches('missing-widget'))->toBeFalse()
        ->and($boundSearch->bindings)->toBe($expectedBindings);
});

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
    $boundSearch = $dialect->jsonSearch(
        new SqlFragment('?', ['document-binding']),
        new SqlFragment('?', ['needle-binding']),
        $path,
    );
    $expectedBindings = match ($family) {
        DatabaseFamily::MySql,
        DatabaseFamily::MariaDb => ['document-binding', 'needle-binding', $path],
        DatabaseFamily::Sqlite => ['document-binding', '$', '$.widgets', '$.widget_key', 'needle-binding'],
        DatabaseFamily::PostgreSql => ['document-binding', $path, 'needle-binding'],
    };

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
        ], 'hero-banner'))->toBeFalse()
        ->and($boundSearch->bindings)->toBe($expectedBindings);
});

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

it('matches active-engine JSON values by the supplied search needle', function (): void {
    $connection = DB::connection();
    $platform = CapellDatabase::for($connection);
    $select = match ($platform->family()) {
        DatabaseFamily::MySql => 'CAST(? AS JSON) AS meta',
        DatabaseFamily::PostgreSql => '?::jsonb AS meta',
        DatabaseFamily::MariaDb,
        DatabaseFamily::Sqlite => '? AS meta',
    };

    $matching = $connection->query()->fromSub(
        fn (Builder $query): Builder => $query->selectRaw(
            $select,
            [json_encode([['data' => 'A Capell needle appears here']], JSON_THROW_ON_ERROR)],
        ),
        'documents',
    );
    $notMatching = clone $matching;
    $dialect = $platform->queryDialect();

    $dialect->jsonSearch(SqlFragment::raw('meta'), SqlFragment::value('needle'), '$[*].data')
        ->applyWhere($matching);
    $dialect->jsonSearch(SqlFragment::raw('meta'), SqlFragment::value('absent'), '$[*].data')
        ->applyWhere($notMatching);

    expect($matching->exists())->toBeTrue()
        ->and($notMatching->exists())->toBeFalse();
});

it('builds schema expressions and reports family capabilities', function (): void {
    $definition = new DatabaseIndexDefinition(
        table: 'insights_events',
        name: 'insights_path_index',
        columns: ['path', 'type'],
        prefixLengths: ['path' => 191],
    );

    $mysql = new MySqlDatabasePlatform;
    $sqlite = new SqliteDatabasePlatform;
    $postgres = new PostgresDatabasePlatform;

    expect($mysql->schemaDialect()->prefixedIndex($definition))
        ->toEqual(new SqlFragment('CREATE INDEX `insights_path_index` ON `insights_events` (`path`(191), `type`)'))
        ->and($sqlite->schemaDialect()->prefixedIndex($definition))
        ->toEqual(new SqlFragment('CREATE INDEX "insights_path_index" ON "insights_events" ("path", "type")'))
        ->and($postgres->schemaDialect()->jsonPathIndex($definition, 'meta', '$.page_id'))
        ->toEqual(new SqlFragment('CREATE INDEX "insights_path_index" ON "insights_events" ((jsonb_path_query_first("meta"::jsonb, \'$.page_id\'::jsonpath)))'))
        ->and($mysql->schemaDialect()->jsonPathIndex($definition, 'meta', '$.page_id'))
        ->toEqual(new SqlFragment('CREATE INDEX `insights_path_index` ON `insights_events` ((CAST(JSON_UNQUOTE(JSON_EXTRACT(`meta`, \'$.page_id\')) AS CHAR(191))))'))
        ->and(fn (): ?SqlFragment => $sqlite->schemaDialect()->jsonPathIndex($definition, 'meta', "$.page_id'); DROP TABLE users; --"))
        ->toThrow(InvalidArgumentException::class, 'Unsafe JSON path')
        ->and($mysql->schemaDialect()->hashColumn('insights_daily_rollups', 'path_digest', 'path'))
        ->toEqual(new SqlFragment('ALTER TABLE `insights_daily_rollups` ADD COLUMN `path_digest` CHAR(64) AS (SHA2(`path`, 256)) STORED'))
        ->and($sqlite->schemaDialect()->supports(DatabaseCapability::PrefixIndex))->toBeFalse()
        ->and($postgres->schemaDialect()->supports(DatabaseCapability::JsonPathIndex))->toBeTrue();
});

it('creates a SQLite JSON path index without DDL bindings', function (): void {
    $connection = new SQLiteConnection(new PDO('sqlite::memory:'), ':memory:', '', ['driver' => 'sqlite']);
    $connection->statement('CREATE TABLE "capell_json_index_test" ("meta" JSON)');

    $definition = new DatabaseIndexDefinition(
        table: 'capell_json_index_test',
        name: 'capell_json_index_test_path',
        columns: ['meta'],
    );
    $fragment = (new SqliteDatabasePlatform)->schemaDialect()
        ->jsonPathIndex($definition, 'meta', '$.page_id');
    throw_unless($fragment instanceof SqlFragment, LogicException::class, 'SQLite did not provide a JSON path index.');

    expect($fragment->bindings)->toBeEmpty()
        ->and($connection->statement($fragment->sql))->toBeTrue()
        ->and($connection->table('sqlite_master')->where('name', 'capell_json_index_test_path')->exists())->toBeTrue();
});

it('finds quoted SQLite constraint names', function (): void {
    $connection = new SQLiteConnection(new PDO('sqlite::memory:'), ':memory:', '', ['driver' => 'sqlite']);
    $connection->statement(
        'CREATE TABLE "capell_quoted_constraint" ("name" TEXT, CONSTRAINT "capell_name_check" CHECK ("name" <> \'\'))',
    );

    expect((new SqliteDatabasePlatform)->schemaDialect()->hasConstraint(
        'capell_quoted_constraint',
        'capell_name_check',
        $connection,
    ))->toBeTrue();
});

it('creates JSON path indexes when the active engine advertises support', function (): void {
    $connection = DB::connection();
    $platform = CapellDatabase::for($connection);
    $family = $platform->family();
    $dialect = $platform->schemaDialect();
    $supportsJsonPathIndex = $dialect->supports(DatabaseCapability::JsonPathIndex, $connection);

    if (! $supportsJsonPathIndex) {
        expect($family)->toBe(DatabaseFamily::MariaDb);

        return;
    }

    $table = 'capell_json_index_active';
    $connection->statement(sprintf('DROP TABLE IF EXISTS %s', $table));
    $connection->statement($family === DatabaseFamily::PostgreSql
        ? sprintf('CREATE TABLE %s (meta JSONB)', $table)
        : sprintf('CREATE TABLE %s (meta JSON)', $table));
    $definition = new DatabaseIndexDefinition(
        table: $table,
        name: 'capell_json_index_active_path',
        columns: ['meta'],
    );

    try {
        $fragment = $dialect->jsonPathIndex($definition, 'meta', '$.page_id');
        throw_unless($fragment instanceof SqlFragment, LogicException::class, 'The active platform did not provide a JSON path index.');

        expect($fragment->bindings)->toBeEmpty()
            ->and($connection->statement($fragment->sql))->toBeTrue();
    } finally {
        $connection->statement(sprintf('DROP TABLE IF EXISTS %s', $table));
    }
});

it('inspects constraints triggers and foreign key references on the active engine', function (): void {
    $connection = DB::connection();
    $platform = CapellDatabase::for($connection);
    $family = $platform->family();
    $originalPrefix = $connection->getTablePrefix();
    $testPrefix = 'capell_meta_';
    $connection->setTablePrefix($testPrefix);
    $parentTable = 'parent';
    $childTable = 'child';
    $physicalParentTable = $testPrefix . $parentTable;
    $physicalChildTable = $testPrefix . $childTable;
    $trigger = 'capell_metadata_trigger';
    $constraint = 'capell_metadata_check';
    $connection->statement(sprintf('DROP TABLE IF EXISTS %s', $physicalChildTable));
    $connection->statement(sprintf('DROP TABLE IF EXISTS %s', $physicalParentTable));

    if ($family === DatabaseFamily::PostgreSql) {
        $connection->unprepared('DROP FUNCTION IF EXISTS capell_metadata_trigger_fn()');
    }

    try {
        $connection->statement(sprintf('CREATE TABLE %s (id INTEGER PRIMARY KEY)', $physicalParentTable));
        $connection->statement(sprintf(
            "CREATE TABLE %s (parent_id INTEGER, name VARCHAR(50), CONSTRAINT %s CHECK (name <> ''), CONSTRAINT capell_metadata_foreign FOREIGN KEY (parent_id) REFERENCES %s (id))",
            $physicalChildTable,
            $constraint,
            $physicalParentTable,
        ));

        match ($family) {
            DatabaseFamily::MySql,
            DatabaseFamily::MariaDb => $connection->unprepared(sprintf(
                'CREATE TRIGGER %s BEFORE INSERT ON %s FOR EACH ROW SET NEW.name = NEW.name',
                $trigger,
                $physicalChildTable,
            )),
            DatabaseFamily::PostgreSql => (function () use ($connection, $trigger, $physicalChildTable): void {
                $connection->unprepared('CREATE FUNCTION capell_metadata_trigger_fn() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RETURN NEW; END $$');
                $connection->unprepared(sprintf(
                    'CREATE TRIGGER %s BEFORE INSERT ON %s FOR EACH ROW EXECUTE FUNCTION capell_metadata_trigger_fn()',
                    $trigger,
                    $physicalChildTable,
                ));
            })(),
            DatabaseFamily::Sqlite => $connection->unprepared(sprintf(
                'CREATE TRIGGER %s AFTER INSERT ON %s BEGIN SELECT 1; END',
                $trigger,
                $physicalChildTable,
            )),
        };

        $dialect = $platform->schemaDialect();
        $generatedColumn = $dialect->generatedColumn(
            $physicalChildTable,
            'name_length',
            match ($family) {
                DatabaseFamily::MySql,
                DatabaseFamily::MariaDb => 'CHAR_LENGTH(name)',
                DatabaseFamily::PostgreSql => 'char_length(name)',
                DatabaseFamily::Sqlite => 'length(name)',
            },
            'INTEGER',
        );
        $connection->statement($generatedColumn->sql, $generatedColumn->bindings);
        $generatedColumnInspection = $dialect->inspectGeneratedColumn(
            $childTable,
            'name_length',
            $connection,
        );
        $generatedColumns = $connection->select(
            $generatedColumnInspection->sql,
            $generatedColumnInspection->bindings,
        );
        $connection->table($parentTable)->insert(['id' => 1]);
        $connection->table($childTable)->insert(['parent_id' => 1, 'name' => 'valid']);
        capellExpectIntegrityViolation(
            fn (): bool => $connection->table($childTable)->insert(['parent_id' => 2, 'name' => 'missing-parent']),
        );
        capellExpectIntegrityViolation(
            fn (): bool => $connection->table($childTable)->insert(['parent_id' => 1, 'name' => '']),
        );

        expect($dialect->hasConstraint($childTable, $constraint, $connection))->toBeTrue()
            ->and($dialect->hasConstraint($childTable, 'missing_constraint', $connection))->toBeFalse()
            ->and($dialect->hasTrigger($trigger, $connection))->toBeTrue()
            ->and($dialect->hasTrigger('missing_trigger', $connection))->toBeFalse()
            ->and($dialect->hasForeignKeyReference(
                $childTable,
                'parent_id',
                $parentTable,
                'id',
                $connection,
            ))->toBeTrue()
            ->and($dialect->hasForeignKeyReference(
                $childTable,
                'parent_id',
                'missing_parent',
                'id',
                $connection,
            ))->toBeFalse()
            ->and($generatedColumns)->not->toBeEmpty()
            ->and($connection->table($childTable)->count())->toBe(1);
    } finally {
        $connection->statement(sprintf('DROP TABLE IF EXISTS %s', $physicalChildTable));
        $connection->statement(sprintf('DROP TABLE IF EXISTS %s', $physicalParentTable));

        if ($family === DatabaseFamily::PostgreSql) {
            $connection->unprepared('DROP FUNCTION IF EXISTS capell_metadata_trigger_fn()');
        }

        $connection->setTablePrefix($originalPrefix);
    }
});

it('caches mysql and mariadb version capabilities per connection', function (): void {
    $pdo = Mockery::mock(PDO::class);
    $pdo->shouldReceive('getAttribute')->once()->with(PDO::ATTR_SERVER_VERSION)->andReturn('10.11.8-MariaDB');
    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('getName')->andReturn('capell_mysql');
    $connection->shouldReceive('getPdo')->once()->andReturn($pdo);

    $dialect = (new MySqlDatabasePlatform)->schemaDialect();

    expect($dialect->supports(DatabaseCapability::GeneratedColumn, $connection))->toBeTrue()
        ->and($dialect->supports(DatabaseCapability::JsonPathIndex, $connection))->toBeFalse()
        ->and($dialect->supports(DatabaseCapability::GeneratedColumn, $connection))->toBeTrue();
});

it('binds the registry and facade as the shared runtime seam', function (): void {
    expect(resolve(DatabasePlatformRegistry::class))->toBe(resolve(DatabasePlatformRegistry::class))
        ->and(CapellDatabase::forDriver('sqlite')->family())->toBe(DatabaseFamily::Sqlite);
});

it('provisions sqlite files and skips empty server database names', function (): void {
    $path = storage_path('framework/testing/capell-platform-provisioner.sqlite');
    File::delete($path);

    try {
        expect((new SqliteDatabasePlatform)->provisioner()->provision('sqlite', ['database' => $path]))->toBe(DatabaseProvisioningResult::Created)
            ->and(File::exists($path))->toBeTrue()
            ->and((new SqliteDatabasePlatform)->provisioner()->provision('sqlite', ['database' => $path]))->toBe(DatabaseProvisioningResult::Ready)
            ->and((new MySqlDatabasePlatform)->provisioner()->provision('mysql', ['database' => ' ']))->toBe(DatabaseProvisioningResult::Unavailable)
            ->and((new PostgresDatabasePlatform)->provisioner()->provision('pgsql', ['database' => '']))->toBe(DatabaseProvisioningResult::Unavailable);
    } finally {
        File::delete($path);
    }
});

it('reports the active database as ready through its matching provisioner', function (): void {
    $connection = DB::connection();
    $connectionName = (string) config('database.default');
    $configuration = config('database.connections.' . $connectionName);
    throw_unless(is_array($configuration), LogicException::class, 'The active test connection must be configured.');
    $platform = CapellDatabase::for($connection);
    $provisioner = $platform->provisioner();
    throw_unless($provisioner !== null, LogicException::class, 'The active database platform must provide a provisioner.');

    if ($platform->family() === DatabaseFamily::Sqlite) {
        $path = storage_path('framework/testing/capell-active-provisioner.sqlite');
        File::delete($path);
        $configuration['database'] = $path;

        try {
            expect($provisioner->provision($connectionName, $configuration))
                ->toBe(DatabaseProvisioningResult::Created)
                ->and($provisioner->provision($connectionName, $configuration))
                ->toBe(DatabaseProvisioningResult::Ready);
        } finally {
            File::delete($path);
        }

        return;
    }

    if ($platform->family() === DatabaseFamily::PostgreSql) {
        $configuration['maintenance_database'] = 'postgres';
    }

    expect($provisioner->provision($connectionName, $configuration))
        ->toBe(DatabaseProvisioningResult::Ready);
});

it('creates reconnects to and reuses a disposable database', function (): void {
    $sourceConnection = DB::connection();
    $platform = CapellDatabase::for($sourceConnection);
    $family = $platform->family();

    $sourceConnectionName = (string) config('database.default');
    $configuration = config('database.connections.' . $sourceConnectionName);
    throw_unless(is_array($configuration), LogicException::class, 'The active test connection must be configured.');
    $database = $family === DatabaseFamily::Sqlite
        ? storage_path(sprintf('framework/testing/capell-provisioner-test-%d.sqlite', getmypid()))
        : sprintf('capell_provisioner_test_%d', getmypid());
    $connectionName = 'capell_provisioner_disposable';
    $configuration['database'] = $database;
    if ($family === DatabaseFamily::Sqlite) {
        File::delete($database);
    }
    Config::set('database.connections.' . $connectionName, $configuration);
    $provisioner = $platform->provisioner();
    throw_unless($provisioner !== null, LogicException::class, 'The active database platform must provide a provisioner.');

    try {
        expect($provisioner->provision($connectionName, $configuration))
            ->toBe(DatabaseProvisioningResult::Created)
            ->and(DB::connection($connectionName)->getDatabaseName())->toBe($database)
            ->and($provisioner->provision($connectionName, $configuration))
            ->toBe(DatabaseProvisioningResult::Ready);
    } finally {
        DB::purge($connectionName);

        if ($family === DatabaseFamily::Sqlite) {
            File::delete($database);
        } else {
            $maintenanceConnectionName = 'capell_provisioner_maintenance';
            $maintenanceConfiguration = $configuration;
            $maintenanceConfiguration['database'] = $family === DatabaseFamily::PostgreSql
                ? (string) ($configuration['maintenance_database'] ?? 'postgres')
                : null;
            Config::set('database.connections.' . $maintenanceConnectionName, $maintenanceConfiguration);
            $dropDatabase = $family === DatabaseFamily::PostgreSql
                ? sprintf('DROP DATABASE IF EXISTS "%s"', $database)
                : sprintf('DROP DATABASE IF EXISTS `%s`', $database);

            try {
                DB::connection($maintenanceConnectionName)->unprepared($dropDatabase);
            } finally {
                DB::purge($maintenanceConnectionName);
                Config::set('database.connections.' . $maintenanceConnectionName);
            }
        }

        Config::set('database.connections.' . $connectionName);
    }
});
