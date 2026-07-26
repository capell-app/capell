<?php

declare(strict_types=1);

use Capell\Core\Contracts\Database\DatabasePlatform;
use Capell\Core\Data\Database\DatabaseIndexDefinition;
use Capell\Core\Data\Database\SqlFragment;
use Capell\Core\Enums\Database\DatabaseCapability;
use Capell\Core\Enums\Database\DatabaseDateOperation;
use Capell\Core\Enums\Database\DatabaseFamily;
use Capell\Core\Exceptions\UnsupportedDatabaseDriver;
use Capell\Core\Facades\CapellDatabase;
use Capell\Core\Support\Database\DatabasePlatformRegistry;
use Capell\Core\Support\Database\Platforms\MariaDbDatabasePlatform;
use Capell\Core\Support\Database\Platforms\MySqlDatabasePlatform;
use Capell\Core\Support\Database\Platforms\PostgresDatabasePlatform;
use Capell\Core\Support\Database\Platforms\SqliteDatabasePlatform;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

it('resolves every supported database driver through one registry seam', function (): void {
    $mysql = new MySqlDatabasePlatform;
    $mariaDb = new MariaDbDatabasePlatform;
    $sqlite = new SqliteDatabasePlatform;
    $postgres = new PostgresDatabasePlatform;
    $registry = new DatabasePlatformRegistry([$mysql, $mariaDb, $sqlite, $postgres]);

    expect($registry->for('mysql'))->toBe($mysql)
        ->and($registry->for('mariadb'))->toBe($mariaDb)
        ->and($registry->for('sqlite'))->toBe($sqlite)
        ->and($registry->for('pgsql'))->toBe($postgres)
        ->and($registry->for('postgresql'))->toBe($postgres);
});

it('resolves configured connections and rejects duplicates and unknown drivers', function (): void {
    $sqlite = new SqliteDatabasePlatform;
    $registry = new DatabasePlatformRegistry([$sqlite, new PostgresDatabasePlatform]);
    $connection = new SQLiteConnection(new PDO('sqlite::memory:'), ':memory:', '', ['driver' => 'sqlite']);

    expect($registry->for($connection))->toBe($sqlite)
        ->and(fn (): DatabasePlatformRegistry => $registry->register(new SqliteDatabasePlatform))
        ->toThrow(LogicException::class, 'Database driver [sqlite] is already registered.')
        ->and(fn (): DatabasePlatform => $registry->for('sqlsrv'))
        ->toThrow(UnsupportedDatabaseDriver::class, 'Unsupported database driver [sqlsrv].');
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
        ? ['featured', '$.tags']
        : ['$.tags', 'featured'];
    $jsonSearchBindings = match ($platform->family()) {
        DatabaseFamily::MySql,
        DatabaseFamily::MariaDb => ['%needle%', '$[*].data'],
        DatabaseFamily::Sqlite => ['$', '$.data', '%needle%'],
        DatabaseFamily::PostgreSql => ['$[*].data', '%needle%'],
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
        ->and($dialect->jsonSearch(SqlFragment::raw('meta'), 'needle', '$[*].data'))
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
            'json_contains' => 'JSON_CONTAINS(meta, JSON_QUOTE(?), ?)',
            'json_search' => 'JSON_SEARCH(meta, \'one\', ?, NULL, ?) IS NOT NULL',
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
            'json_contains' => 'JSON_CONTAINS(meta, JSON_QUOTE(?), ?)',
            'json_search' => 'JSON_SEARCH(meta, \'one\', ?, NULL, ?) IS NOT NULL',
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
            'elapsed' => 'CAST((julianday(finished_at) - julianday(started_at)) * 86400 AS INTEGER)',
            'json_extract' => 'json_extract(meta, ?)',
            'json_contains' => 'EXISTS (SELECT 1 FROM json_each(meta, ?) WHERE value = ?)',
            'json_search' => 'EXISTS (SELECT 1 FROM json_each(meta, ?) WHERE CAST(json_extract(value, ?) AS TEXT) LIKE ?)',
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
            'json_contains' => 'jsonb_path_exists(meta::jsonb, ?::jsonpath, jsonb_build_object(\'value\', to_jsonb(?::text)))',
            'json_search' => 'EXISTS (SELECT 1 FROM jsonb_path_query(meta::jsonb, ?::jsonpath) AS capell_json_search(value) WHERE capell_json_search.value #>> \'{}\' ILIKE ?)',
        ],
    ],
]);

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

    $dialect->jsonSearch(SqlFragment::raw('meta'), 'needle', '$[*].data')
        ->applyWhere($matching);
    $dialect->jsonSearch(SqlFragment::raw('meta'), 'absent', '$[*].data')
        ->applyWhere($notMatching);

    expect($matching->exists())->toBeTrue()
        ->and($notMatching->exists())->toBeFalse();
});

it('matches PostgreSQL JSON values by the supplied search needle', function (): void {
    $connection = DB::connection();

    if ($connection->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('PostgreSQL JSON behaviour requires the pgsql test connection.');
    }

    $matching = $connection->query()->fromSub(
        fn (Builder $query): Builder => $query->selectRaw(
            '?::jsonb AS meta',
            [json_encode([['data' => 'A Capell needle appears here']], JSON_THROW_ON_ERROR)],
        ),
        'documents',
    );
    $notMatching = clone $matching;
    $dialect = (new PostgresDatabasePlatform)->queryDialect();

    $dialect->jsonSearch(SqlFragment::raw('meta'), 'needle', '$[*].data')
        ->applyWhere($matching);
    $dialect->jsonSearch(SqlFragment::raw('meta'), 'absent', '$[*].data')
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
        ->toEqual(new SqlFragment('CREATE INDEX "insights_path_index" ON "insights_events" ((jsonb_path_query_first("meta"::jsonb, ?::jsonpath)))', ['$.page_id']))
        ->and($mysql->schemaDialect()->hashColumn('insights_daily_rollups', 'path_digest', 'path'))
        ->toEqual(new SqlFragment('ALTER TABLE `insights_daily_rollups` ADD COLUMN `path_digest` CHAR(64) AS (SHA2(`path`, 256)) STORED'))
        ->and($sqlite->schemaDialect()->supports(DatabaseCapability::FullTextIndex))->toBeFalse()
        ->and($postgres->schemaDialect()->supports(DatabaseCapability::JsonPathIndex))->toBeTrue();
});

it('caches mysql and mariadb version capabilities per connection', function (): void {
    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('getName')->andReturn('capell_mysql');
    $connection->shouldReceive('getServerVersion')->once()->andReturn('10.11.8-MariaDB');

    $dialect = (new MySqlDatabasePlatform)->schemaDialect();

    expect($dialect->supports(DatabaseCapability::GeneratedColumn, $connection))->toBeTrue()
        ->and($dialect->supports(DatabaseCapability::JsonPathIndex, $connection))->toBeFalse()
        ->and($dialect->supports(DatabaseCapability::GeneratedColumn, $connection))->toBeTrue();
});

it('binds the registry and facade as the shared runtime seam', function (): void {
    expect(resolve(DatabasePlatformRegistry::class))->toBe(resolve(DatabasePlatformRegistry::class))
        ->and(CapellDatabase::for('sqlite')->family())->toBe(DatabaseFamily::Sqlite);
});

it('provisions sqlite files and skips empty server database names', function (): void {
    $path = storage_path('framework/testing/capell-platform-provisioner.sqlite');
    File::delete($path);

    try {
        expect((new SqliteDatabasePlatform)->provisioner()->provision('sqlite', ['database' => $path]))->toBeTrue()
            ->and(File::exists($path))->toBeTrue()
            ->and((new SqliteDatabasePlatform)->provisioner()->provision('sqlite', ['database' => $path]))->toBeFalse()
            ->and((new MySqlDatabasePlatform)->provisioner()->provision('mysql', ['database' => ' ']))->toBeFalse()
            ->and((new PostgresDatabasePlatform)->provisioner()->provision('pgsql', ['database' => '']))->toBeFalse();
    } finally {
        File::delete($path);
    }
});
