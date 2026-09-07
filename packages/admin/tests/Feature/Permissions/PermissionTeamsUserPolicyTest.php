<?php

declare(strict_types=1);

use Capell\Admin\Policies\UserPolicy;
use Capell\Admin\Tests\Fixtures\Models\RealSiteScopedAdminResourceUser;
use Capell\Core\Models\Site;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function (): void {
    config(['permission.teams' => true]);
    resolve(PermissionRegistrar::class)->teams = true;
    resolve(PermissionRegistrar::class)->forgetCachedPermissions();
});

afterEach(function (): void {
    auth()->logout();
    resolve(PermissionRegistrar::class)->setPermissionsTeamId(null);
    resolve(PermissionRegistrar::class)->teams = false;
    resolve(PermissionRegistrar::class)->forgetCachedPermissions();
    config(['permission.teams' => false]);
});

function cap0532CoreUserPolicyActor(Site $assignedSite): RealSiteScopedAdminResourceUser
{
    $assignedSiteId = $assignedSite->getKey();
    assert(is_int($assignedSiteId));

    resolve(PermissionRegistrar::class)->setPermissionsTeamId($assignedSiteId);

    $role = Role::query()->firstOrCreate([
        'name' => 'cap-0532-core-user-policy-test-role',
        'guard_name' => 'web',
    ]);

    foreach (['ViewAny:User', 'View:User', 'Update:User', 'Delete:User'] as $permissionName) {
        $permission = Permission::findOrCreate($permissionName, 'web');
        $role->givePermissionTo($permission);
    }

    $user = new RealSiteScopedAdminResourceUser;
    $user->forceFill([
        'name' => 'CAP-0532 core UserPolicy actor',
        'email' => fake()->unique()->safeEmail(),
        'password' => bcrypt('password'),
    ]);
    $user->save();
    $user->assignRoleForSite($assignedSite, $role);

    return $user;
}

it('denies User view, update, and delete for a direct record carrying another site role', function (): void {
    $assignedSite = Site::factory()->createOne();
    $otherSite = Site::factory()->createOne();
    $user = cap0532CoreUserPolicyActor($assignedSite);

    $otherRecord = new RealSiteScopedAdminResourceUser;
    $otherRecord->forceFill([
        'name' => 'CAP-0532 other-site user record',
        'email' => fake()->unique()->safeEmail(),
        'password' => bcrypt('password'),
    ]);
    $otherRecord->save();

    $otherSiteRole = Role::query()->firstOrCreate([
        'name' => 'cap-0532-core-user-policy-other-site-role',
        'guard_name' => 'web',
    ]);
    $otherRecord->assignRoleForSite($otherSite, $otherSiteRole);

    resolve(PermissionRegistrar::class)->setPermissionsTeamId($otherSite->getKey());
    resolve(PermissionRegistrar::class)->forgetCachedPermissions();

    $policy = new UserPolicy;

    expect((new UserPolicy)->view($user, $otherRecord))->toBeFalse()
        ->and($policy->update($user, $otherRecord))->toBeFalse()
        ->and($policy->delete($user, $otherRecord))->toBeFalse();
});
