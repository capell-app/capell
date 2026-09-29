<?php

declare(strict_types=1);

use Capell\Core\Facades\CapellCore;
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

it('preserves every Core event and expiry timestamp on MariaDB 10.5 with implicit defaults disabled', function (): void {
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
        expect(CapellCore::getMigrations())->toContain('2026_09_29_000001_remove_implicit_timestamp_updates');
    } finally {
        DB::purge('timestamp_proof');
        $server->exec('DROP DATABASE `' . $database . '`');
    }

    $databases = $server->query('SHOW DATABASES');
    throw_if($databases === false, RuntimeException::class, 'Unable to verify proof database cleanup.');
    expect($databases->fetchAll(PDO::FETCH_COLUMN))->not->toContain($database);
});
