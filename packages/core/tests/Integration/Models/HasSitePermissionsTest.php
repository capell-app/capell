<?php

declare(strict_types=1);

use Capell\Core\Models\Site;
use Capell\Core\Tests\Support\Models\HasSitePermissionsTestUser;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

function makeSitePermissionUserForTest(): HasSitePermissionsTestUser
{
    $user = new HasSitePermissionsTestUser;

    $user->forceFill([
        'name' => 'Site Scoped User',
        'email' => fake()->unique()->safeEmail(),
        'password' => bcrypt('password'),
    ]);
    $user->save();

    return $user;
}

it('does not treat roles scoped to the active site as global roles for another site', function (): void {
    $activeSite = Site::factory()->createOne();
    $otherSite = Site::factory()->createOne();
    $role = Role::query()->create(['name' => 'site-editor', 'guard_name' => 'web']);
    $user = makeSitePermissionUserForTest();

    DB::table('model_has_roles')->insert([
        'role_id' => $role->getKey(),
        'model_type' => $user->getMorphClass(),
        'model_id' => $user->getKey(),
        'team_id' => $activeSite->getKey(),
    ]);

    resolve(PermissionRegistrar::class)->setPermissionsTeamId($activeSite->getKey());

    expect($user->hasRoleForSite($otherSite, $role))->toBeFalse();
});

it('treats null-team roles as global roles for every site', function (): void {
    $site = Site::factory()->createOne();
    $role = Role::query()->create(['name' => 'global-editor', 'guard_name' => 'web']);
    $user = makeSitePermissionUserForTest();

    $user->assignRole($role);

    resolve(PermissionRegistrar::class)->setPermissionsTeamId($site->getKey());

    expect($user->hasRoleForSite($site, $role))->toBeTrue();
});

it('assigns removes and lists roles within a temporary site permission scope', function (): void {
    $site = Site::factory()->createOne();
    $otherSite = Site::factory()->createOne();
    $role = Role::query()->create(['name' => 'site-reviewer', 'guard_name' => 'web']);
    $scopeRestoreRole = Role::query()->create(['name' => 'scope-restore-check', 'guard_name' => 'web']);
    $user = makeSitePermissionUserForTest();
    $registrar = resolve(PermissionRegistrar::class);

    $registrar->setPermissionsTeamId($otherSite->getKey());

    $user->assignRoleForSite($site, $scopeRestoreRole);
    $user->removeRoleForSite($site, $scopeRestoreRole);

    DB::table('model_has_roles')->insert([
        'role_id' => $role->getKey(),
        'model_type' => $user->getMorphClass(),
        'model_id' => $user->getKey(),
        'team_id' => $site->getKey(),
    ]);

    expect($registrar->getPermissionsTeamId())->toBe($otherSite->getKey())
        ->and($user->getRolesForSite($site)->pluck('name')->all())->toBe(['site-reviewer'])
        ->and($user->hasRoleForSite($site, 'site-reviewer'))->toBeTrue()
        ->and($user->getAssignedSiteIds()->all())->toBe([$site->getKey()])
        ->and($user->isGlobalAdmin())->toBeFalse();

    DB::table('model_has_roles')
        ->where('role_id', $role->getKey())
        ->where('model_type', $user->getMorphClass())
        ->where('model_id', $user->getKey())
        ->where('team_id', $site->getKey())
        ->delete();

    expect($registrar->getPermissionsTeamId())->toBe($otherSite->getKey())
        ->and($user->getRolesForSite($site))->toHaveCount(0)
        ->and($user->hasRoleForSite($site, 'site-reviewer'))->toBeFalse()
        ->and($user->getAssignedSiteIds())->toHaveCount(0);
});

it('checks permissions against the requested site and restores the previous team id', function (): void {
    $site = Site::factory()->createOne();
    $otherSite = Site::factory()->createOne();
    $role = Role::query()->create(['name' => 'site-publisher', 'guard_name' => 'web']);
    $permission = Permission::query()->create(['name' => 'publish pages', 'guard_name' => 'web']);
    $role->givePermissionTo($permission);
    $user = makeSitePermissionUserForTest();
    $registrar = resolve(PermissionRegistrar::class);

    $user->assignRoleForSite($site, $role);
    DB::table('model_has_roles')->insertOrIgnore([
        'role_id' => $role->getKey(),
        'model_type' => $user->getMorphClass(),
        'model_id' => $user->getKey(),
        'team_id' => $site->getKey(),
    ]);
    $registrar->setPermissionsTeamId($otherSite->getKey());

    expect($user->hasPermissionForSite($site, 'publish pages'))->toBeTrue()
        ->and($registrar->getPermissionsTeamId())->toBe($otherSite->getKey());
});
