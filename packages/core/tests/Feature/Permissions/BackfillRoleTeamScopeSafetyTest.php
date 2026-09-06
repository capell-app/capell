<?php

declare(strict_types=1);

use Capell\Core\Models\Site;
use Capell\Core\Tests\Support\Models\HasSitePermissionsTestUser;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function (): void {
    config(['permission.teams' => true]);
    resolve(PermissionRegistrar::class)->teams = true;
    resolve(PermissionRegistrar::class)->forgetCachedPermissions();
});

afterEach(function (): void {
    resolve(PermissionRegistrar::class)->setPermissionsTeamId(null);
    resolve(PermissionRegistrar::class)->teams = false;
    resolve(PermissionRegistrar::class)->forgetCachedPermissions();
    config(['permission.teams' => false]);
});

/**
 * Keep the assertion reusable when the real data backfill is introduced.
 * Existing global role assignments are deliberately part of the contract:
 * Spatie interprets a null team_id as a global role assignment.
 *
 * @param  Closure(): void  $backfill
 */
function assertGlobalRoleAssignmentsRemainUnscoped(Closure $backfill): void
{
    $before = DB::table('model_has_roles')
        ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
        ->where('roles.name', 'super_admin')
        ->where('roles.guard_name', 'web')
        ->whereNull('model_has_roles.team_id')
        ->orderBy('model_has_roles.model_type')
        ->orderBy('model_has_roles.model_id')
        ->pluck('model_has_roles.model_type', 'model_has_roles.model_id')
        ->all();

    $backfill();

    $after = DB::table('model_has_roles')
        ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
        ->where('roles.name', 'super_admin')
        ->where('roles.guard_name', 'web')
        ->whereNull('model_has_roles.team_id')
        ->orderBy('model_has_roles.model_type')
        ->orderBy('model_has_roles.model_id')
        ->pluck('model_has_roles.model_type', 'model_has_roles.model_id')
        ->all();

    expect($after)->toBe($before)
        ->and(DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('roles.name', 'super_admin')
            ->where('roles.guard_name', 'web')
            ->whereNotNull('model_has_roles.team_id')
            ->exists())->toBeFalse();
}

it('preserves null team ids on existing super admin assignments', function (): void {
    $assignedSite = Site::factory()->create();
    $globalRole = Role::findOrCreate('super_admin', 'web');
    $siteRole = Role::query()->create(['name' => 'editor', 'guard_name' => 'web']);
    $globalUser = new HasSitePermissionsTestUser;
    $globalUser->forceFill([
        'name' => 'Global Administrator',
        'email' => fake()->unique()->safeEmail(),
        'password' => bcrypt('password'),
    ]);
    $globalUser->save();

    $legacySiteUser = new HasSitePermissionsTestUser;
    $legacySiteUser->forceFill([
        'name' => 'Legacy Site Editor',
        'email' => fake()->unique()->safeEmail(),
        'password' => bcrypt('password'),
    ]);
    $legacySiteUser->save();

    DB::table('model_has_roles')->insert([
        [
            'role_id' => $globalRole->getKey(),
            'model_type' => $globalUser->getMorphClass(),
            'model_id' => $globalUser->getKey(),
            'team_id' => null,
        ],
        [
            'role_id' => $siteRole->getKey(),
            'model_type' => $legacySiteUser->getMorphClass(),
            'model_id' => $legacySiteUser->getKey(),
            'team_id' => null,
        ],
    ]);

    assertGlobalRoleAssignmentsRemainUnscoped(function () use ($assignedSite, $globalRole): void {
        DB::table('model_has_roles')
            ->whereNull('team_id')
            ->where('role_id', '!=', $globalRole->getKey())
            ->update(['team_id' => $assignedSite->getKey()]);
    });

    expect(DB::table('model_has_roles')
        ->where('role_id', $globalRole->getKey())
        ->where('model_id', $globalUser->getKey())
        ->value('team_id'))->toBeNull();
});
