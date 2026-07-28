<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    config([
        'permission.table_names' => [
            'roles' => 'cap_gu_roles',
            'model_has_roles' => 'cap_gu_mhr',
            'model_has_permissions' => 'cap_gu_mhp',
        ],
        'permission.column_names' => [
            'team_foreign_key' => 'team_id',
            'model_morph_key' => 'model_id',
            'role_pivot_key' => 'role_id',
            'permission_pivot_key' => 'permission_id',
        ],
    ]);

    Schema::create('cap_gu_roles', static function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('team_id')->nullable();
        $table->string('name');
        $table->string('guard_name');
        $table->unique(['team_id', 'name', 'guard_name']);
    });

    Schema::create('cap_gu_mhr', static function (Blueprint $table): void {
        $table->unsignedBigInteger('team_id')->nullable();
        $table->unsignedBigInteger('role_id');
        $table->unsignedBigInteger('model_id');
        $table->string('model_type');
        $table->unique(
            ['team_id', 'role_id', 'model_id', 'model_type'],
            'cap_gu_mhr_team_role_model_type_unique',
        );
    });

    Schema::create('cap_gu_mhp', static function (Blueprint $table): void {
        $table->unsignedBigInteger('team_id')->nullable();
        $table->unsignedBigInteger('permission_id');
        $table->unsignedBigInteger('model_id');
        $table->string('model_type');
        $table->unique(
            ['team_id', 'permission_id', 'model_id', 'model_type'],
            'cap_gu_mhp_team_permission_model_type_unique',
        );
    });
});

afterEach(function (): void {
    Schema::dropIfExists('cap_gu_mhp');
    Schema::dropIfExists('cap_gu_mhr');
    Schema::dropIfExists('cap_gu_roles');
});

it('enforces fresh-install uniqueness without changing NULL global scope semantics', function (): void {
    $migration = require dirname(__DIR__, 3) . '/database/migrations/2026_07_23_000002_enforce_global_permission_team_uniqueness.php';
    $migration->up();

    expect(Schema::hasColumn('cap_gu_roles', 'capell_team_scope_key'))->toBeTrue()
        ->and(Schema::hasColumn('cap_gu_mhr', 'capell_team_scope_key'))->toBeTrue()
        ->and(Schema::hasColumn('cap_gu_mhp', 'capell_team_scope_key'))->toBeTrue();

    DB::table('cap_gu_roles')->insert([
        'team_id' => null,
        'name' => 'super-admin',
        'guard_name' => 'web',
    ]);
    DB::table('cap_gu_mhr')->insert([
        'team_id' => null,
        'role_id' => 10,
        'model_id' => 20,
        'model_type' => 'user',
    ]);
    DB::table('cap_gu_mhp')->insert([
        'team_id' => null,
        'permission_id' => 30,
        'model_id' => 20,
        'model_type' => 'user',
    ]);

    expect(DB::table('cap_gu_roles')->whereNull('team_id')->count())->toBe(1)
        ->and(fn (): bool => DB::table('cap_gu_roles')->insert([
            'team_id' => null,
            'name' => 'super-admin',
            'guard_name' => 'web',
        ]))->toThrow(QueryException::class)
        ->and(fn (): bool => DB::table('cap_gu_mhr')->insert([
            'team_id' => null,
            'role_id' => 10,
            'model_id' => 20,
            'model_type' => 'user',
        ]))->toThrow(QueryException::class)
        ->and(fn (): bool => DB::table('cap_gu_mhp')->insert([
            'team_id' => null,
            'permission_id' => 30,
            'model_id' => 20,
            'model_type' => 'user',
        ]))->toThrow(QueryException::class);

    DB::table('cap_gu_roles')->insert([
        ['team_id' => 1, 'name' => 'editor', 'guard_name' => 'web'],
        ['team_id' => 2, 'name' => 'editor', 'guard_name' => 'web'],
    ]);
});

it('upgrades clean existing global assignments and remains idempotent', function (): void {
    DB::table('cap_gu_roles')->insert([
        'team_id' => null,
        'name' => 'super-admin',
        'guard_name' => 'web',
    ]);
    DB::table('cap_gu_mhr')->insert([
        'team_id' => null,
        'role_id' => 10,
        'model_id' => 20,
        'model_type' => 'user',
    ]);
    DB::table('cap_gu_mhp')->insert([
        'team_id' => null,
        'permission_id' => 30,
        'model_id' => 20,
        'model_type' => 'user',
    ]);

    $migration = require dirname(__DIR__, 3) . '/database/migrations/2026_07_23_000002_enforce_global_permission_team_uniqueness.php';
    $migration->up();
    $migration->up();

    expect(DB::table('cap_gu_roles')->whereNull('team_id')->count())->toBe(1)
        ->and(DB::table('cap_gu_mhr')->whereNull('team_id')->count())->toBe(1)
        ->and(DB::table('cap_gu_mhp')->whereNull('team_id')->count())->toBe(1);
});

it('fails before schema mutation when an upgraded install contains ambiguous global duplicates', function (): void {
    DB::table('cap_gu_mhr')->insert([
        ['team_id' => null, 'role_id' => 10, 'model_id' => 20, 'model_type' => 'user'],
        ['team_id' => null, 'role_id' => 10, 'model_id' => 20, 'model_type' => 'user'],
    ]);

    $migration = require dirname(__DIR__, 3) . '/database/migrations/2026_07_23_000002_enforce_global_permission_team_uniqueness.php';

    expect(fn () => $migration->up())
        ->toThrow(RuntimeException::class, 'duplicate NULL-team records')
        ->and(Schema::hasColumn('cap_gu_roles', 'capell_team_scope_key'))->toBeFalse()
        ->and(Schema::hasColumn('cap_gu_mhr', 'capell_team_scope_key'))->toBeFalse()
        ->and(Schema::hasColumn('cap_gu_mhp', 'capell_team_scope_key'))->toBeFalse();
});
