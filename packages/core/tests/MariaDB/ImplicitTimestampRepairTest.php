<?php

declare(strict_types=1);

use Capell\Core\Contracts\Database\RepairsImplicitTimestampUpdates;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Facades\CapellDatabase;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

it('repairs legacy MariaDB 10.5 implicit timestamp updates through an explicitly named connection', function (): void {
    $connectionName = getenv('CAPELL_MARIADB_10_5_CONNECTION');
    throw_unless(is_string($connectionName) && $connectionName !== '', RuntimeException::class, 'Set CAPELL_MARIADB_10_5_CONNECTION to a disposable MariaDB 10.5 connection before running phpunit.mariadb.xml.');

    /** @var array{driver: string, host: string, port: int|string, username: string, password: string}|null $serviceConfiguration */
    $serviceConfiguration = config('database.connections.' . $connectionName);
    throw_unless(is_array($serviceConfiguration) && in_array($serviceConfiguration['driver'], ['mysql', 'mariadb'], true), RuntimeException::class, 'The named MariaDB 10.5 connection must use the mysql or mariadb driver.');

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
    $versionQuery = $server->query('SELECT VERSION()');
    throw_if($versionQuery === false, RuntimeException::class, 'Unable to read the MariaDB service version.');

    $version = $versionQuery->fetchColumn();
    expect($version)->toContain('10.5.')->toContain('MariaDB');
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
        expect((int) $connection->selectOne('SELECT @@GLOBAL.explicit_defaults_for_timestamp AS value')->value)->toBe(0)
            ->and((int) $connection->selectOne('SELECT @@SESSION.explicit_defaults_for_timestamp AS value')->value)->toBe(0);
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
        expect(CapellCore::getMigrations())->toContain('2026_09_29_000001_remove_implicit_timestamp_updates');
    } finally {
        DB::purge('timestamp_proof');
        $server->exec('DROP DATABASE `' . $database . '`');
    }

    $databases = $server->query('SHOW DATABASES');
    throw_if($databases === false, RuntimeException::class, 'Unable to verify proof database cleanup.');
    expect($databases->fetchAll(PDO::FETCH_COLUMN))->not->toContain($database);
});
