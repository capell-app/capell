<?php

declare(strict_types=1);

use Capell\Core\Models\Site;
use Capell\Core\Tests\Support\Models\HasSitePermissionsTestUser;
use Spatie\Permission\Models\Permission;
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

it('keeps role and permission helpers tied to the requested team id', function (): void {
    $assignedSite = Site::factory()->create();
    $otherSite = Site::factory()->create();
    resolve(PermissionRegistrar::class)->setPermissionsTeamId($assignedSite->getKey());
    $permission = Permission::query()->create(['name' => 'publish site content', 'guard_name' => 'web']);
    $role = Role::query()->create(['name' => 'site-publisher', 'guard_name' => 'web']);
    $role->givePermissionTo($permission);

    $user = new HasSitePermissionsTestUser;
    $user->forceFill([
        'name' => 'Site Publisher',
        'email' => fake()->unique()->safeEmail(),
        'password' => bcrypt('password'),
    ]);
    $user->save();

    $user->assignRoleForSite($assignedSite, $role);

    $freshUser = $user->fresh();
    throw_unless($freshUser instanceof HasSitePermissionsTestUser);

    expect($user->getAssignedSiteIds()->all())->toBe([$assignedSite->getKey()])
        ->and($user->hasRoleForSite($assignedSite, $role))->toBeTrue()
        ->and($user->hasRoleForSite($otherSite, $role))->toBeFalse()
        ->and($user->hasPermissionForSite($assignedSite, 'publish site content'))->toBeTrue()
        ->and($freshUser->hasPermissionForSite($otherSite, 'publish site content'))->toBeFalse();
});
