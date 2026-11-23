<?php

declare(strict_types=1);

use Capell\Core\Support\TableName;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * Laravel's mariadb driver creates uuid columns as MariaDB's native UUID type; SQLite and the
 * mysql driver create text, so only a MariaDB server reached through the mariadb driver can
 * show this fault. Declared only in phpunit.mariadb.xml; it throws, never skips, without a server.
 */
it('stores and compares numeric keys in asset attachments on the mariadb driver', function (): void {
    $connectionName = getenv('CAPELL_MARIADB_CONNECTION');
    throw_unless(is_string($connectionName) && $connectionName !== '', RuntimeException::class, 'Set CAPELL_MARIADB_CONNECTION to a disposable MariaDB connection before running phpunit.mariadb.xml.');

    /** @var array{driver: string, host: string, port: int|string, username: string, password: string}|null $server */
    $server = config('database.connections.' . $connectionName);
    throw_unless(is_array($server) && in_array($server['driver'], ['mysql', 'mariadb'], true), RuntimeException::class, 'The named MariaDB connection must use the mysql or mariadb driver.');

    $pdo = new PDO(sprintf('mysql:host=%s;port=%s', $server['host'], $server['port']), $server['username'], $server['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $database = 'capell_asset_keys_test_' . getmypid() . '_' . bin2hex(random_bytes(4));
    $pdo->exec('CREATE DATABASE `' . $database . '`');
    $previous = DB::getDefaultConnection();

    try {
        config(['database.connections.asset_keys_proof' => [
            ...$server,
            'driver' => 'mariadb',
            'database' => $database,
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'strict' => true,
        ]]);
        $connection = DB::connection('asset_keys_proof');
        $migrations = dirname(__DIR__, 2) . '/database/migrations/';

        // The create migration stamps its rows with users, so that table exists first, as on a site.
        $connection->statement('CREATE TABLE users (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY)');
        expect(Artisan::call('migrate', [
            '--database' => 'asset_keys_proof',
            '--force' => true,
            '--realpath' => true,
            '--path' => [
                $migrations . '2026_05_10_190832_15_create_asset_attachments_table.php',
                $migrations . '2026_11_22_000010_store_asset_attachment_keys_as_text.php',
            ],
        ]))->toBe(0, Artisan::output());

        DB::setDefaultConnection('asset_keys_proof');
        $attachments = TableName::resolve('asset_attachments');

        // A page (numeric key) uses a media item (numeric key): the ordinary case on every site.
        $connection->table($attachments)->insert(['related_type' => 'page', 'related_id' => '9', 'asset_type' => 'media', 'asset_id' => '12', 'order' => 0]);
        $connection->statement('CREATE TABLE numeric_owners (id BIGINT UNSIGNED PRIMARY KEY)');
        $connection->table('numeric_owners')->insert([['id' => 12], ['id' => 13]]);

        $used = $connection->table('numeric_owners')
            ->whereExists(static fn ($query) => $query->from($attachments)->whereColumn($attachments . '.asset_id', 'numeric_owners.id'))
            ->pluck('id')
            ->all();

        expect($used)->toBe([12])
            ->and($connection->getSchemaBuilder()->getColumnType($attachments, 'related_id'))->toBe('char')
            ->and($connection->getSchemaBuilder()->getColumnType($attachments, 'asset_id'))->toBe('char')
            ->and($connection->getSchemaBuilder()->hasIndex($attachments, ['asset_type', 'asset_id']))->toBeTrue();
    } finally {
        DB::setDefaultConnection($previous);
        DB::purge('asset_keys_proof');
        $pdo->exec('DROP DATABASE IF EXISTS `' . $database . '`');
    }
});
