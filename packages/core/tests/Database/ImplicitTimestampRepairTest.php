<?php

declare(strict_types=1);

use Capell\Core\Facades\CapellCore;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

it('preserves every Core event and expiry timestamp on an independent SQLite connection', function (): void {
    $migration = require dirname(__DIR__, 2) . '/database/migrations/2026_09_29_000001_remove_implicit_timestamp_updates.php';
    expect($migration)->toBeInstanceOf(Migration::class);
    $columns = new ReflectionClass($migration)->getConstant('COLUMNS');
    throw_unless(is_array($columns), RuntimeException::class, 'Expected a Core timestamp repair map.');
    config(['database.connections.timestamp_proof' => [
        'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => 'proof_',
    ]]);
    try {
        $connection = DB::connection('timestamp_proof');
        foreach ($columns as $table => $column) {
            $connection->statement(sprintf('CREATE TABLE "proof_%s" (id INTEGER PRIMARY KEY, "%s" TIMESTAMP NOT NULL, value INTEGER NOT NULL)', $table, $column));
            $connection->table($table)->insert(['id' => 1, $column => '2020-01-01 00:00:00', 'value' => 0]);
        }

        $schemaBefore = $connection->getSchemaBuilder()->getTables();
        new ReflectionProperty($migration, 'connection')->setValue($migration, 'timestamp_proof');
        $migration->up();
        $migration->up();
        expect($connection->getSchemaBuilder()->getTables())->toEqual($schemaBefore);
        foreach ($columns as $table => $column) {
            $connection->table($table)->where('id', 1)->update(['value' => 1]);
            expect($connection->table($table)->value($column))->toBe('2020-01-01 00:00:00');
        }

        expect(CapellCore::getMigrations())->toContain('2026_09_29_000001_remove_implicit_timestamp_updates');
    } finally {
        DB::purge('timestamp_proof');
    }
});
