<?php

declare(strict_types=1);

use Capell\Core\Facades\CapellCore;
use Capell\Core\Facades\CapellDatabase;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

function timestampProofEnvironment(string $name): string
{
    $value = getenv($name);
    throw_if(! is_string($value) || $value === '', RuntimeException::class, 'Run the MariaDB proof through ./capell pest; missing service environment: ' . $name);

    return $value;
}

/** @return array<string, string> */
function coreImplicitTimestampColumns(): array
{
    return [
        'capell_upgrade_log' => 'ran_at',
        'capell_upgrade_run_events' => 'occurred_at',
        'content_locks' => 'expires_at',
        'layout_content_snapshots' => 'taken_at',
        'blueprint_schema_snapshots' => 'taken_at',
        'stored_events' => 'created_at',
        'page_revisions' => 'occurred_at',
        'metric_collection_runs' => 'started_at',
        'activity_buckets' => 'bucket_started_at',
        'editor_scratch_drafts' => 'saved_at',
        'activity_visitors' => 'first_seen_at',
    ];
}

it('preserves every Core event and expiry timestamp on SQLite and repairs legacy MariaDB implicit updates', function (): void {
    $host = getenv('DB_HOST');
    if (! is_string($host) || $host === '') {
        // The standard release suite uses SQLite without a MariaDB service.
        // Exercise its no-op contract rather than skipping the repair test.
        config(['database.connections.timestamp_proof' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => 'proof_',
        ]]);
        try {
            $connection = DB::connection('timestamp_proof');
            foreach (coreImplicitTimestampColumns() as $table => $column) {
                $connection->statement(sprintf('CREATE TABLE "proof_%s" (id INTEGER PRIMARY KEY, "%s" TIMESTAMP NOT NULL, value INTEGER NOT NULL)', $table, $column));
                $connection->table($table)->insert(['id' => 1, $column => '2020-01-01 00:00:00', 'value' => 0]);
            }

            $schemaBefore = $connection->getSchemaBuilder()->getTables();
            $migration = require dirname(__DIR__, 2) . '/database/migrations/2026_09_29_000001_remove_implicit_timestamp_updates.php';
            new ReflectionProperty($migration, 'connection')->setValue($migration, 'timestamp_proof');
            $migration->up();
            $migration->up();
            expect($connection->getSchemaBuilder()->getTables())->toEqual($schemaBefore);
            foreach (coreImplicitTimestampColumns() as $table => $column) {
                $connection->table($table)->where('id', 1)->update(['value' => 1]);
                expect($connection->table($table)->value($column))->toBe('2020-01-01 00:00:00');
            }

            expect(CapellCore::getMigrations())->toContain('2026_09_29_000001_remove_implicit_timestamp_updates');
        } finally {
            DB::purge('timestamp_proof');
        }

        return;
    }

    $server = new PDO(
        sprintf('mysql:host=%s;port=%s', timestampProofEnvironment('DB_HOST'), timestampProofEnvironment('DB_PORT')),
        timestampProofEnvironment('DB_USERNAME'),
        timestampProofEnvironment('DB_PASSWORD'),
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
            'host' => timestampProofEnvironment('DB_HOST'),
            'port' => timestampProofEnvironment('DB_PORT'),
            'database' => $database,
            'username' => timestampProofEnvironment('DB_USERNAME'),
            'password' => timestampProofEnvironment('DB_PASSWORD'),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => 'proof_',
            'strict' => true,
        ]]);
        $connection = DB::connection('timestamp_proof');
        $connection->statement('SET SESSION explicit_defaults_for_timestamp = 0');
        foreach (coreImplicitTimestampColumns() as $table => $column) {
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

        $migration = require dirname(__DIR__, 2) . '/database/migrations/2026_09_29_000001_remove_implicit_timestamp_updates.php';
        expect($migration)->toBeInstanceOf(Migration::class);
        new ReflectionProperty($migration, 'connection')->setValue($migration, 'timestamp_proof');
        $migration->up();
        $schemaAfter = $connection->select('SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, COLUMN_DEFAULT, IS_NULLABLE, EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME, ORDINAL_POSITION', [$database]);
        $migration->up();
        expect($connection->select('SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, COLUMN_DEFAULT, IS_NULLABLE, EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME, ORDINAL_POSITION', [$database]))->toEqual($schemaAfter);
        expect($connection->selectOne('SHOW CREATE TABLE proof_unchanged'))->toEqual($unchangedBefore);
        foreach (coreImplicitTimestampColumns() as $table => $column) {
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
        expect(CapellCore::getMigrations())->toContain('2026_09_29_000001_remove_implicit_timestamp_updates');
    } finally {
        DB::purge('timestamp_proof');
        $server->exec('DROP DATABASE `' . $database . '`');
    }

    $databases = $server->query('SHOW DATABASES');
    throw_if($databases === false, RuntimeException::class, 'Unable to verify proof database cleanup.');
    expect($databases->fetchAll(PDO::FETCH_COLUMN))->not->toContain($database);
});
