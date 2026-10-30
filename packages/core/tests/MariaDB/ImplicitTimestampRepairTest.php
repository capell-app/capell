<?php

declare(strict_types=1);

use Capell\Core\Contracts\Database\RepairsImplicitTimestampUpdates;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Facades\CapellDatabase;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('repairs implicit timestamp updates under legacy MariaDB semantics through an explicitly named connection', function (): void {
    $connectionName = getenv('CAPELL_MARIADB_CONNECTION');
    throw_unless(is_string($connectionName) && $connectionName !== '', RuntimeException::class, 'Set CAPELL_MARIADB_CONNECTION to a disposable MariaDB connection before running phpunit.mariadb.xml.');

    /** @var array{driver: string, host: string, port: int|string, username: string, password: string}|null $serviceConfiguration */
    $serviceConfiguration = config('database.connections.' . $connectionName);
    throw_unless(is_array($serviceConfiguration) && in_array($serviceConfiguration['driver'], ['mysql', 'mariadb'], true), RuntimeException::class, 'The named MariaDB connection must use the mysql or mariadb driver.');

    $migration = require dirname(__DIR__, 2) . '/database/migrations/2026_09_29_000001_remove_implicit_timestamp_updates.php';
    expect($migration)->toBeInstanceOf(Migration::class);
    $columns = new ReflectionClass($migration)->getConstant('COLUMNS');
    throw_unless(is_array($columns), RuntimeException::class, 'Expected a Core timestamp repair map.');

    $server = new PDO(
        sprintf('mysql:host=%s;port=%s', $serviceConfiguration['host'], $serviceConfiguration['port']),
        $serviceConfiguration['username'],
        $serviceConfiguration['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );
    $server->exec('SET SESSION explicit_defaults_for_timestamp = 0');

    $sessionQuery = $server->query('SELECT @@SESSION.explicit_defaults_for_timestamp');
    throw_if($sessionQuery === false, RuntimeException::class, 'Unable to read the MariaDB timestamp session setting.');
    expect((int) $sessionQuery->fetchColumn())->toBe(0);
    $database = 'capell_timestamp_test_' . getmypid() . '_' . bin2hex(random_bytes(4));
    $server->exec('CREATE DATABASE `' . $database . '`');
    try {
        config(['database.connections.timestamp_proof' => [
            'driver' => 'mysql',
            'host' => $serviceConfiguration['host'],
            'port' => $serviceConfiguration['port'],
            'database' => $database,
            'username' => $serviceConfiguration['username'],
            'password' => $serviceConfiguration['password'],
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => 'proof_',
            'strict' => true,
        ]]);
        $connection = DB::connection('timestamp_proof');
        $connection->statement('SET SESSION explicit_defaults_for_timestamp = 0');
        expect((int) $connection->selectOne('SELECT @@SESSION.explicit_defaults_for_timestamp AS value')->value)->toBe(0);
        foreach ($columns as $table => $column) {
            $connection->statement(sprintf('CREATE TABLE `proof_%s` (`id` INT PRIMARY KEY, `%s` TIMESTAMP NOT NULL, `value` INT NOT NULL)', $table, $column));
            $connection->statement(sprintf("INSERT INTO `proof_%s` VALUES (1, '2020-01-01 00:00:00', 0)", $table));
            $connection->statement(sprintf('UPDATE `proof_%s` SET `value` = 1 WHERE `id` = 1', $table));
            expect($connection->table($table)->value($column))->not->toBe('2020-01-01 00:00:00');
            $connection->statement(sprintf("UPDATE `proof_%s` SET `%s` = '2020-01-01 00:00:00' WHERE `id` = 1", $table, $column));
        }

        // A non-Core table and intentional updated_at semantics must stay unchanged.
        $connection->statement('CREATE TABLE proof_foreign_events (id INT PRIMARY KEY, occurred_at TIMESTAMP NOT NULL, value INT NOT NULL)');
        $connection->statement('ALTER TABLE proof_page_revisions ADD updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP');
        $connection->statement("CREATE TABLE proof_unchanged (id INT PRIMARY KEY, occurred_at TIMESTAMP NOT NULL DEFAULT '2020-01-01 00:00:00')");
        $unchangedBefore = $connection->selectOne('SHOW CREATE TABLE proof_unchanged');

        new ReflectionProperty($migration, 'connection')->setValue($migration, 'timestamp_proof');
        $migration->up();
        $schemaAfter = $connection->select('SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, COLUMN_DEFAULT, IS_NULLABLE, EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME, ORDINAL_POSITION', [$database]);
        $migration->up();
        expect($connection->select('SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, COLUMN_DEFAULT, IS_NULLABLE, EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME, ORDINAL_POSITION', [$database]))->toEqual($schemaAfter);
        expect($connection->selectOne('SHOW CREATE TABLE proof_unchanged'))->toEqual($unchangedBefore);
        foreach ($columns as $table => $column) {
            $connection->statement(sprintf('UPDATE `proof_%s` SET `value` = 2 WHERE `id` = 1', $table));
            expect($connection->table($table)->value($column))->toBe('2020-01-01 00:00:00');
            $metadata = $connection->selectOne('SELECT EXTRA, IS_NULLABLE, COLUMN_DEFAULT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?', [$database, 'proof_' . $table, $column]);
            expect(strtolower((string) $metadata->EXTRA))->not->toContain('on update')
                ->and($metadata->IS_NULLABLE)->toBe('NO')
                ->and($metadata->COLUMN_DEFAULT)->not->toBeNull();
        }

        // Exercise attribute preservation through the published migration itself.
        $connection->statement("ALTER TABLE proof_content_locks MODIFY expires_at TIMESTAMP(6) NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP(6) COMMENT 'Nullable expiry'");
        $connection->statement("ALTER TABLE proof_page_revisions MODIFY occurred_at TIMESTAMP(3) NOT NULL DEFAULT '2020-02-03 04:05:06.123' ON UPDATE CURRENT_TIMESTAMP(3) COMMENT 'Event''s original time'");
        $connection->statement('ALTER TABLE proof_page_revisions ADD INDEX original_event_time (occurred_at)');
        $connection->statement('ALTER TABLE proof_stored_events MODIFY created_at TIMESTAMP(4) NOT NULL DEFAULT CURRENT_TIMESTAMP(4) ON UPDATE CURRENT_TIMESTAMP(4)');
        $connection->statement("ALTER TABLE proof_activity_visitors MODIFY first_seen_at DATETIME NOT NULL DEFAULT '2020-01-01 00:00:00' ON UPDATE CURRENT_TIMESTAMP");
        $connection->statement('ALTER TABLE proof_capell_upgrade_run_events MODIFY occurred_at TIMESTAMP(6) NOT NULL DEFAULT (CURRENT_TIMESTAMP(6) + INTERVAL 1 DAY)');
        $metadataQuery = 'SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, COLUMN_DEFAULT, IS_NULLABLE, COLUMN_COMMENT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME, ORDINAL_POSITION';
        $attributesBefore = $connection->select($metadataQuery, [$database]);
        $indexesBefore = $connection->getSchemaBuilder()->getIndexes('page_revisions');
        $safeBefore = $connection->selectOne('SHOW CREATE TABLE proof_capell_upgrade_run_events');
        $nonTimestampBefore = $connection->selectOne('SHOW CREATE TABLE proof_activity_visitors');

        $migration->up();
        $migration->up();
        $migration->down();

        expect($connection->select($metadataQuery, [$database]))->toEqual($attributesBefore)
            ->and($connection->getSchemaBuilder()->getIndexes('page_revisions'))->toEqual($indexesBefore)
            ->and($connection->selectOne('SHOW CREATE TABLE proof_capell_upgrade_run_events'))->toEqual($safeBefore)
            ->and($connection->selectOne('SHOW CREATE TABLE proof_activity_visitors'))->toEqual($nonTimestampBefore);
        foreach (['content_locks' => 'expires_at', 'page_revisions' => 'occurred_at', 'stored_events' => 'created_at'] as $table => $column) {
            expect($connection->table($table)->where('id', 1)->value($column))->toStartWith('2020-01-01 00:00:00');
            $connection->table($table)->insert(['id' => 2, 'value' => 0]);
            $timestampBefore = $connection->table($table)->where('id', 2)->value($column);
            match ($table) {
                'content_locks' => expect($timestampBefore)->toBeNull(),
                'page_revisions' => expect($timestampBefore)->toBe('2020-02-03 04:05:06.123'),
                'stored_events' => expect($timestampBefore)->not->toBeNull(),
            };
            $connection->table($table)->where('id', 2)->update(['value' => 1]);
            expect($connection->table($table)->where('id', 2)->value($column))->toBe($timestampBefore);
            $metadata = $connection->selectOne('SELECT EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?', [$database, 'proof_' . $table, $column]);
            expect(strtolower((string) $metadata->EXTRA))->not->toContain('on update');
        }

        expect(strtolower((string) $connection->selectOne('SELECT EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?', [$database, 'proof_foreign_events', 'occurred_at'])->EXTRA))->toContain('on update');
        expect(strtolower((string) $connection->selectOne('SELECT EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?', [$database, 'proof_page_revisions', 'updated_at'])->EXTRA))->toContain('on update');

        $connection->statement("CREATE TABLE proof_timestamp_attributes (
            id INT PRIMARY KEY,
            nullable_at TIMESTAMP(6) NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP(6) COMMENT 'Nullable event',
            literal_at TIMESTAMP(3) NOT NULL DEFAULT '2020-02-03 04:05:06.123' ON UPDATE CURRENT_TIMESTAMP(3) COMMENT 'Event''s original time',
            current_at TIMESTAMP(4) NOT NULL DEFAULT CURRENT_TIMESTAMP(4) ON UPDATE CURRENT_TIMESTAMP(4),
            value INT NOT NULL,
            INDEX nullable_timestamp (nullable_at)
        )");
        $indexesBefore = $connection->getSchemaBuilder()->getIndexes('timestamp_attributes');
        $dialect = CapellDatabase::for($connection)->schemaDialect();
        expect($dialect)->toBeInstanceOf(RepairsImplicitTimestampUpdates::class);
        assert($dialect instanceof RepairsImplicitTimestampUpdates);
        foreach (['nullable_at', 'literal_at', 'current_at'] as $column) {
            $metadataQuery = 'SELECT COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, COLUMN_COMMENT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?';
            $bindings = [$database, 'proof_timestamp_attributes', $column];
            $before = $connection->selectOne($metadataQuery, $bindings);
            $dialect->dropImplicitTimestampUpdate('timestamp_attributes', $column, $connection);
            expect($connection->selectOne($metadataQuery, $bindings))->toEqual($before);
            $dialect->dropImplicitTimestampUpdate('timestamp_attributes', $column, $connection);
            expect($connection->selectOne($metadataQuery, $bindings))->toEqual($before);
        }

        expect($connection->getSchemaBuilder()->getIndexes('timestamp_attributes'))->toEqual($indexesBefore);
        $connection->table('timestamp_attributes')->insert(['id' => 1, 'value' => 0]);
        $timestampsBefore = $connection->table('timestamp_attributes')->firstOrFail(['nullable_at', 'literal_at', 'current_at']);
        expect($timestampsBefore->nullable_at)->toBeNull()
            ->and($timestampsBefore->literal_at)->toBe('2020-02-03 04:05:06.123')
            ->and($timestampsBefore->current_at)->not->toBeNull();
        $connection->table('timestamp_attributes')->where('id', 1)->update(['value' => 1]);
        expect($connection->table('timestamp_attributes')->firstOrFail(['nullable_at', 'literal_at', 'current_at']))->toEqual($timestampsBefore);

        $connection->statement('CREATE TABLE proof_expression_default (
            id INT PRIMARY KEY,
            occurred_at TIMESTAMP(6) NOT NULL DEFAULT (CURRENT_TIMESTAMP(6) + INTERVAL 1 DAY) ON UPDATE CURRENT_TIMESTAMP(6)
        )');
        $expressionBefore = $connection->selectOne('SHOW CREATE TABLE proof_expression_default');
        expect(fn () => $dialect->dropImplicitTimestampUpdate('expression_default', 'occurred_at', $connection))
            ->toThrow(RuntimeException::class, 'unsupported TIMESTAMP default');
        expect($connection->selectOne('SHOW CREATE TABLE proof_expression_default'))->toEqual($expressionBefore);

        $connection->statement('ALTER TABLE proof_capell_upgrade_log MODIFY ran_at TIMESTAMP(6) NOT NULL DEFAULT (CURRENT_TIMESTAMP(6) + INTERVAL 1 DAY) ON UPDATE CURRENT_TIMESTAMP(6)');
        $expressionBefore = $connection->selectOne('SHOW CREATE TABLE proof_capell_upgrade_log');
        expect(fn () => $migration->up())->toThrow(RuntimeException::class, 'unsupported TIMESTAMP default');
        expect($connection->selectOne('SHOW CREATE TABLE proof_capell_upgrade_log'))->toEqual($expressionBefore);
        expect(CapellCore::getMigrations())->toContain('2026_09_29_000001_remove_implicit_timestamp_updates');
    } finally {
        DB::purge('timestamp_proof');
        $server->exec('DROP DATABASE `' . $database . '`');
    }

    $databases = $server->query('SHOW DATABASES');
    throw_if($databases === false, RuntimeException::class, 'Unable to verify proof database cleanup.');
    expect($databases->fetchAll(PDO::FETCH_COLUMN))->not->toContain($database);
});

it('installs Core with host unsigned integer keys and configured table names on MariaDB', function (array $naming, string $pageTable, bool $foreignPage, string $connectionPrefix = ''): void {
    $connectionName = getenv('CAPELL_MARIADB_CONNECTION');
    throw_unless(is_string($connectionName) && $connectionName !== '', RuntimeException::class, 'Set CAPELL_MARIADB_CONNECTION to an owned disposable MariaDB connection.');
    $service = config('database.connections.' . $connectionName);
    throw_unless(is_array($service) && in_array($service['driver'] ?? null, ['mysql', 'mariadb'], true), RuntimeException::class, 'The named connection must use MySQL or MariaDB.');
    $server = new PDO(sprintf('mysql:host=%s;port=%s', $service['host'], $service['port']), $service['username'], $service['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $database = 'capell_naming_test_' . getmypid() . '_' . bin2hex(random_bytes(4));
    $server->exec('CREATE DATABASE `' . $database . '`');
    $previous = DB::getDefaultConnection();

    try {
        config()->set('database.connections.table_naming_mariadb', [...$service, 'database' => $database, 'prefix' => $connectionPrefix, 'prefix_indexes' => true]);
        config()->set('capell.database', $naming);
        if ($foreignPage) {
            config()->set('settings.repositories.database.table', 'content_settings');
            config()->set('permission.table_names.roles', 'content_roles');
            config()->set('permission.table_names.permissions', 'content_permissions');
        }

        DB::setDefaultConnection('table_naming_mariadb');
        Schema::create('users', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->timestamps();
        });
        if ($foreignPage) {
            Schema::create('pages', function (Blueprint $table): void {
                $table->id();
                $table->string('foreign_data');
            });
            DB::table('pages')->insert(['foreign_data' => 'preserved']);
        }

        $settings = require dirname(__DIR__, 4) . '/vendor/spatie/laravel-settings/database/migrations/create_settings_table.php.stub';
        $permissions = require dirname(__DIR__, 4) . '/vendor/spatie/laravel-permission/database/migrations/create_permission_tables.php.stub';
        $settings->up();
        $permissions->up();
        $repository = resolve('migrator')->getRepository();
        $repository->createRepository();
        $repository->log('2026_05_10_190824_create_permission_tables', 1);
        $exit = Artisan::call('migrate', ['--path' => realpath(__DIR__ . '/../../database/migrations'), '--realpath' => true, '--force' => true]);
        expect($exit)->toBe(0)->and(Schema::hasTable($pageTable))->toBeTrue();
        $columns = collect(Schema::getColumns($pageTable))->keyBy('name');
        expect($columns->get('created_by')['type'])->toContain('int')->toContain('unsigned')->not->toContain('bigint');
        $foreignKeys = Schema::getForeignKeys($pageTable);
        expect(collect($foreignKeys)->contains(fn (array $key): bool => $key['foreign_table'] === $connectionPrefix . 'users' && in_array('created_by', $key['columns'], true)))->toBeTrue();
        if ($foreignPage) {
            expect(DB::table('pages')->value('foreign_data'))->toBe('preserved');
        }

        if ($naming === [] && $connectionPrefix === '') {
            $snapshot = [];
            foreach (Schema::getTables() as $table) {
                $name = $table['name'];
                if (in_array($name, ['migrations', 'users'], true)) {
                    continue;
                }

                $indexes = array_column(Schema::getIndexes($name), 'name');
                sort($indexes);
                $keys = Schema::getForeignKeys($name);
                foreach ($keys as &$key) {
                    unset($key['on_update'], $key['on_delete'], $key['foreign_schema']);
                }

                unset($key);
                usort($keys, fn (array $a, array $b): int => strcmp(json_encode($a), json_encode($b)));
                $snapshot[$name] = ['indexes' => $indexes, 'foreign_keys' => $keys];
            }

            ksort($snapshot);
            $expected = json_decode(file_get_contents(dirname(__DIR__) . '/fixtures/table-naming/main-mariadb-default.json'), true, flags: JSON_THROW_ON_ERROR);
            expect($snapshot)->toBe($expected);
        }
    } finally {
        DB::setDefaultConnection($previous);
        DB::purge('table_naming_mariadb');
        $server->exec('DROP DATABASE `' . $database . '`');
    }
})->with([
    'defaults' => [[], 'pages', false],
    'prefix' => [['table_prefix' => 'cms_'], 'cms_pages', false],
    'DB_PREFIX' => [[], 'pages', false, 'dbp_'],
    'aliases' => [['table_aliases' => ['pages' => 'content_pages', 'translations' => 'content_translations']], 'content_pages', true],
]);
