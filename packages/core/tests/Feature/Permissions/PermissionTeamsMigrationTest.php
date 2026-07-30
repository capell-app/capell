<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    config([
        'permission.table_names' => [
            'roles' => 'cap_mig_roles',
            'model_has_roles' => 'cap_mig_mhr',
            'model_has_permissions' => 'cap_mig_mhp',
        ],
        'permission.column_names' => [
            'team_foreign_key' => 'team_id',
            'model_morph_key' => 'model_id',
            'role_pivot_key' => 'role_id',
            'permission_pivot_key' => 'permission_id',
        ],
    ]);

    Schema::create('cap_mig_roles', static function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->string('guard_name');
        $table->unique(['name', 'guard_name']);
    });

    Schema::create('cap_mig_mhr', static function (Blueprint $table): void {
        $table->unsignedBigInteger('role_id');
        $table->string('model_type');
        $table->unsignedBigInteger('model_id');
        $table->primary(
            ['role_id', 'model_id', 'model_type'],
            'model_has_roles_role_model_type_primary',
        );
    });

    Schema::create('cap_mig_mhp', static function (Blueprint $table): void {
        $table->unsignedBigInteger('permission_id');
        $table->string('model_type');
        $table->unsignedBigInteger('model_id');
        $table->primary(
            ['permission_id', 'model_id', 'model_type'],
            'model_has_permissions_permission_model_type_primary',
        );
    });
});

afterEach(function (): void {
    Schema::dropIfExists('cap_mig_mhp');
    Schema::dropIfExists('cap_mig_mhr');
    Schema::dropIfExists('cap_mig_roles');
});

it('rejects malformed table configuration before schema mutation', function (
    string $key,
    mixed $value,
    string $message,
): void {
    config()->set('permission.table_names.' . $key, $value);
    $migration = require dirname(__DIR__, 3) . '/database/migrations/2026_05_10_190832_19_add_team_id_to_permission_tables.php';

    expect(fn () => $migration->up())->toThrow(RuntimeException::class, $message)
        ->and(Schema::hasColumn('cap_mig_roles', 'team_id'))->toBeFalse()
        ->and(Schema::hasColumn('cap_mig_mhr', 'team_id'))->toBeFalse()
        ->and(Schema::hasColumn('cap_mig_mhp', 'team_id'))->toBeFalse();
})->with([
    'empty roles table' => [
        'roles',
        '',
        'Permission table name [roles] must be a non-empty string.',
    ],
    'blank model roles table' => [
        'model_has_roles',
        '   ',
        'Permission table name [model_has_roles] must be a non-empty string.',
    ],
    'unsafe model permissions table' => [
        'model_has_permissions',
        'model_has_permissions; DROP TABLE roles',
        'Permission table name [model_has_permissions] must be a safe SQL identifier.',
    ],
]);

it('adds team-compatible permission schema idempotently and reverses it safely', function (): void {
    $migration = require dirname(__DIR__, 3) . '/database/migrations/2026_05_10_190832_19_add_team_id_to_permission_tables.php';

    $migration->up();
    $migration->up();

    expect(Schema::hasColumn('cap_mig_roles', 'team_id'))->toBeTrue()
        ->and(Schema::hasIndex('cap_mig_roles', 'cap_mig_roles_team_foreign_key_index'))->toBeTrue()
        ->and(Schema::hasIndex('cap_mig_roles', 'cap_mig_roles_team_id_name_guard_name_unique', 'unique'))->toBeTrue()
        ->and(Schema::hasIndex('cap_mig_roles', 'cap_mig_roles_name_guard_name_unique', 'unique'))->toBeFalse()
        ->and(Schema::hasColumn('cap_mig_mhr', 'team_id'))->toBeTrue()
        ->and(Schema::hasIndex(
            'cap_mig_mhr',
            ['team_id', 'role_id', 'model_id', 'model_type'],
            'unique',
        ))->toBeTrue()
        ->and(Schema::hasIndex(
            'cap_mig_mhr',
            ['role_id', 'model_id', 'model_type'],
            'primary',
        ))->toBeFalse()
        ->and(Schema::hasColumn('cap_mig_mhp', 'team_id'))->toBeTrue()
        ->and(Schema::hasIndex(
            'cap_mig_mhp',
            ['team_id', 'permission_id', 'model_id', 'model_type'],
            'unique',
        ))->toBeTrue()
        ->and(Schema::hasIndex(
            'cap_mig_mhp',
            ['permission_id', 'model_id', 'model_type'],
            'primary',
        ))->toBeFalse();

    DB::table('cap_mig_roles')->insert([
        'team_id' => 1,
        'name' => 'editor',
        'guard_name' => 'web',
    ]);

    expect(fn (): bool => DB::table('cap_mig_roles')->insert([
        'team_id' => 1,
        'name' => 'editor',
        'guard_name' => 'web',
    ]))->toThrow(QueryException::class);

    DB::table('cap_mig_mhr')->insert([
        ['team_id' => null, 'role_id' => 10, 'model_id' => 20, 'model_type' => 'user'],
        ['team_id' => null, 'role_id' => 10, 'model_id' => 20, 'model_type' => 'user'],
    ]);
    DB::table('cap_mig_mhp')->insert([
        ['team_id' => null, 'permission_id' => 30, 'model_id' => 20, 'model_type' => 'user'],
        ['team_id' => null, 'permission_id' => 30, 'model_id' => 20, 'model_type' => 'user'],
    ]);

    DB::table('cap_mig_mhr')->insert([
        ['team_id' => 1, 'role_id' => 10, 'model_id' => 20, 'model_type' => 'user'],
        ['team_id' => 2, 'role_id' => 10, 'model_id' => 20, 'model_type' => 'user'],
    ]);
    DB::table('cap_mig_mhp')->insert([
        ['team_id' => 1, 'permission_id' => 30, 'model_id' => 20, 'model_type' => 'user'],
        ['team_id' => 2, 'permission_id' => 30, 'model_id' => 20, 'model_type' => 'user'],
    ]);

    expect(fn (): bool => DB::table('cap_mig_mhr')->insert([
        'team_id' => 1,
        'role_id' => 10,
        'model_id' => 20,
        'model_type' => 'user',
    ]))->toThrow(QueryException::class)
        ->and(fn (): bool => DB::table('cap_mig_mhp')->insert([
            'team_id' => 1,
            'permission_id' => 30,
            'model_id' => 20,
            'model_type' => 'user',
        ]))->toThrow(QueryException::class);

    expect(fn () => $migration->down())
        ->toThrow(RuntimeException::class, 'contains team-scoped records');

    expect(Schema::hasColumn('cap_mig_roles', 'team_id'))->toBeTrue()
        ->and(Schema::hasIndex(
            'cap_mig_mhr',
            ['team_id', 'role_id', 'model_id', 'model_type'],
            'unique',
        ))->toBeTrue()
        ->and(DB::table('cap_mig_mhr')->count())->toBe(4)
        ->and(DB::table('cap_mig_mhp')->count())->toBe(4);

    DB::table('cap_mig_mhp')->whereNotNull('team_id')->delete();
    DB::table('cap_mig_mhr')->whereNotNull('team_id')->delete();
    DB::table('cap_mig_roles')->whereNotNull('team_id')->delete();

    expect(fn () => $migration->down())
        ->toThrow(RuntimeException::class, 'conflict with its legacy unique constraint');

    expect(DB::table('cap_mig_mhr')->count())->toBe(2)
        ->and(DB::table('cap_mig_mhp')->count())->toBe(2);

    DB::table('cap_mig_mhp')->delete();
    DB::table('cap_mig_mhr')->delete();

    DB::table('cap_mig_mhr')->insert([
        'team_id' => null,
        'role_id' => 10,
        'model_id' => 20,
        'model_type' => 'user',
    ]);
    DB::table('cap_mig_mhp')->insert([
        'team_id' => null,
        'permission_id' => 30,
        'model_id' => 20,
        'model_type' => 'user',
    ]);

    $migration->down();
    $migration->down();

    expect(Schema::hasColumn('cap_mig_roles', 'team_id'))->toBeFalse()
        ->and(Schema::hasIndex('cap_mig_roles', 'cap_mig_roles_name_guard_name_unique', 'unique'))->toBeTrue()
        ->and(Schema::hasColumn('cap_mig_mhr', 'team_id'))->toBeFalse()
        ->and(Schema::hasIndex(
            'cap_mig_mhr',
            ['role_id', 'model_id', 'model_type'],
            'primary',
        ))->toBeTrue()
        ->and(Schema::hasColumn('cap_mig_mhp', 'team_id'))->toBeFalse()
        ->and(Schema::hasIndex(
            'cap_mig_mhp',
            ['permission_id', 'model_id', 'model_type'],
            'primary',
        ))->toBeTrue();
});

it('normalizes an existing Spatie team primary key and rolls it back to the legacy primary key', function (): void {
    Schema::drop('cap_mig_mhp');
    Schema::drop('cap_mig_mhr');
    Schema::drop('cap_mig_roles');

    Schema::create('cap_mig_roles', static function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('team_id')->nullable();
        $table->index('team_id', 'cap_mig_roles_team_foreign_key_index');
        $table->string('name');
        $table->string('guard_name');
        $table->unique(['team_id', 'name', 'guard_name']);
    });

    Schema::create('cap_mig_mhr', static function (Blueprint $table): void {
        $table->unsignedBigInteger('role_id');
        $table->string('model_type');
        $table->unsignedBigInteger('model_id');
        $table->unsignedBigInteger('team_id');
        $table->index('team_id', 'cap_mig_mhr_team_foreign_key_index');
        $table->primary(
            ['team_id', 'role_id', 'model_id', 'model_type'],
            'model_has_roles_role_model_type_primary',
        );
    });

    Schema::create('cap_mig_mhp', static function (Blueprint $table): void {
        $table->unsignedBigInteger('permission_id');
        $table->string('model_type');
        $table->unsignedBigInteger('model_id');
        $table->unsignedBigInteger('team_id');
        $table->index('team_id', 'cap_mig_mhp_team_foreign_key_index');
        $table->primary(
            ['team_id', 'permission_id', 'model_id', 'model_type'],
            'model_has_permissions_permission_model_type_primary',
        );
    });

    $migration = require dirname(__DIR__, 3) . '/database/migrations/2026_05_10_190832_19_add_team_id_to_permission_tables.php';

    $migration->up();
    $migration->up();

    expect(Schema::hasIndex(
        'cap_mig_mhr',
        ['team_id', 'role_id', 'model_id', 'model_type'],
        'primary',
    ))->toBeFalse()
        ->and(Schema::hasIndex(
            'cap_mig_mhr',
            ['team_id', 'role_id', 'model_id', 'model_type'],
            'unique',
        ))->toBeTrue()
        ->and(Schema::hasIndex(
            'cap_mig_mhp',
            ['team_id', 'permission_id', 'model_id', 'model_type'],
            'primary',
        ))->toBeFalse()
        ->and(Schema::hasIndex(
            'cap_mig_mhp',
            ['team_id', 'permission_id', 'model_id', 'model_type'],
            'unique',
        ))->toBeTrue();

    DB::table('cap_mig_mhr')->insert([
        ['team_id' => null, 'role_id' => 10, 'model_id' => 20, 'model_type' => 'user'],
        ['team_id' => null, 'role_id' => 10, 'model_id' => 20, 'model_type' => 'user'],
    ]);
    DB::table('cap_mig_mhp')->insert([
        ['team_id' => null, 'permission_id' => 30, 'model_id' => 20, 'model_type' => 'user'],
        ['team_id' => null, 'permission_id' => 30, 'model_id' => 20, 'model_type' => 'user'],
    ]);

    expect(DB::table('cap_mig_mhr')->count())->toBe(2)
        ->and(DB::table('cap_mig_mhp')->count())->toBe(2);

    DB::table('cap_mig_mhr')->delete();
    DB::table('cap_mig_mhp')->delete();

    DB::table('cap_mig_mhr')->insert([
        'team_id' => null,
        'role_id' => 10,
        'model_id' => 20,
        'model_type' => 'user',
    ]);
    DB::table('cap_mig_mhp')->insert([
        'team_id' => null,
        'permission_id' => 30,
        'model_id' => 20,
        'model_type' => 'user',
    ]);

    $migration->down();
    $migration->down();

    expect(Schema::hasColumn('cap_mig_mhr', 'team_id'))->toBeFalse()
        ->and(Schema::hasIndex(
            'cap_mig_mhr',
            ['role_id', 'model_id', 'model_type'],
            'primary',
        ))->toBeTrue()
        ->and(Schema::hasColumn('cap_mig_mhp', 'team_id'))->toBeFalse()
        ->and(Schema::hasIndex(
            'cap_mig_mhp',
            ['permission_id', 'model_id', 'model_type'],
            'primary',
        ))->toBeTrue();
});
