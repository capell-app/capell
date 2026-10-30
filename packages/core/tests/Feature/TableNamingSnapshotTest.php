<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Fixtures were generated from the pinned origin/main migration files and Blueprint macros.
it('preserves main migration index and foreign-key names with the default table configuration', function (string $prefix, string $snapshot): void {
    $previous = DB::getDefaultConnection();
    config()->set('database.connections.naming_snapshot', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => $prefix, 'prefix_indexes' => false, 'foreign_key_constraints' => false]);
    config()->set('capell.database', []);
    DB::setDefaultConnection('naming_snapshot');
    try {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('email');
        });
        (require dirname(__DIR__, 4) . '/vendor/spatie/laravel-settings/database/migrations/create_settings_table.php.stub')->up();
        (require dirname(__DIR__, 4) . '/vendor/spatie/laravel-permission/database/migrations/create_permission_tables.php.stub')->up();
        $repository = resolve('migrator')->getRepository();
        $repository->createRepository();
        $repository->log('2026_05_10_190824_create_permission_tables', 1);
        $exit = Artisan::call('migrate', ['--path' => realpath(__DIR__ . '/../../database/migrations'), '--realpath' => true, '--force' => true]);
        expect($exit)->toBe(0);
        $data = [];
        foreach (Schema::getTables() as $table) {
            $physical = $table['name'];
            if ($prefix !== '' && ! str_starts_with((string) $physical, $prefix)) {
                continue;
            }

            $name = $prefix === '' ? $physical : substr((string) $physical, strlen($prefix));
            if (in_array($name, ['migrations', 'users'], true)) {
                continue;
            }

            $indexes = array_column(Schema::getIndexes($name), 'name');
            sort($indexes);
            $keys = Schema::getForeignKeys($name);
            foreach ($keys as &$key) {
                unset($key['on_update'], $key['on_delete']);
            }

            unset($key);
            usort($keys, fn (array $a, array $b): int => strcmp(json_encode($a), json_encode($b)));
            $data[$name] = ['indexes' => $indexes, 'foreign_keys' => $keys];
        }

        ksort($data);
        $expected = json_decode(file_get_contents(__DIR__ . '/../fixtures/table-naming/' . $snapshot), true, flags: JSON_THROW_ON_ERROR);
        if ($prefix !== '') {
            // Main accidentally applied the connection prefix twice when restoring these keys.
            $corrected = 0;
            foreach ($expected as &$table) {
                foreach ($table['foreign_keys'] as &$key) {
                    if (in_array($key['foreign_table'], ['dbp_dbp_roles', 'dbp_dbp_permissions'], true)) {
                        $key['foreign_table'] = substr($key['foreign_table'], strlen($prefix));
                        $corrected++;
                    }
                }

                unset($key);
            }

            unset($table);
            expect($corrected)->toBe(2);
        }

        expect($data)->toBe($expected);
    } finally {
        DB::setDefaultConnection($previous);
        DB::purge('naming_snapshot');
    }
})->with(['default' => ['', 'main-default.json'], 'DB_PREFIX' => ['dbp_', 'main-dbprefix.json']]);
