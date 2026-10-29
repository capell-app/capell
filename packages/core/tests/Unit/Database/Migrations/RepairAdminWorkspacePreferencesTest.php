<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('repairs only missing workspace preferences without deleting existing data or custom user tables', function (): void {
    $previous = DB::getDefaultConnection();
    config()->set('database.connections.workspace_repair_test', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]);
    DB::setDefaultConnection('workspace_repair_test');
    $migration = require dirname(__DIR__, 4) . '/database/migrations/2026_10_29_000001_repair_admin_workspace_preferences_on_users_table.php';

    try {
        Schema::create('custom_users', fn (Blueprint $table) => $table->id());
        $migration->up();
        expect(Schema::hasTable('users'))->toBeFalse()
            ->and(Schema::hasColumn('custom_users', 'admin_workspace_preferences'))->toBeFalse();

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });
        DB::table('users')->insert(['name' => 'Editor']);
        $migration->up();
        expect(Schema::hasColumn('users', 'admin_workspace_preferences'))->toBeTrue()
            ->and(DB::table('users')->value('name'))->toBe('Editor');
        DB::table('users')->update(['admin_workspace_preferences' => '{"dashboard":"wide"}']);
        $migration->up();
        $migration->down();
        expect(DB::table('users')->value('admin_workspace_preferences'))->toBe('{"dashboard":"wide"}')
            ->and(Schema::hasColumn('users', 'admin_workspace_preferences'))->toBeTrue();
    } finally {
        DB::setDefaultConnection($previous);
        DB::purge('workspace_repair_test');
    }
});
