<?php

declare(strict_types=1);

use Capell\Admin\Policies\LayoutPolicy;
use Capell\Admin\Policies\MediaPolicy;
use Capell\Admin\Policies\RedirectPolicy;
use Capell\Admin\Policies\SitePolicy;
use Capell\Admin\Tests\Fixtures\Models\RealSiteScopedAdminResourceUser;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Media;
use Capell\Core\Models\PageUrl;
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

function cap0532CorePolicyActor(Site $assignedSite, string $permissionName): RealSiteScopedAdminResourceUser
{
    resolve(PermissionRegistrar::class)->setPermissionsTeamId($assignedSite->getKey());

    $permission = Permission::findOrCreate($permissionName, 'web');
    $role = Role::query()->firstOrCreate([
        'name' => 'cap-0532-core-policy-test-role-' . $permissionName,
        'guard_name' => 'web',
    ]);
    $role->givePermissionTo($permission);

    $user = new RealSiteScopedAdminResourceUser;
    $user->forceFill([
        'name' => 'CAP-0532 policy test user',
        'email' => fake()->unique()->safeEmail(),
        'password' => bcrypt('password'),
    ]);
    $user->save();
    $user->assignRoleForSite($assignedSite, $role);

    return $user;
}

it('denies Layout view for a direct record belonging to another site', function (): void {
    $assignedSite = Site::factory()->createOne();
    $otherSite = Site::factory()->createOne();
    $user = cap0532CorePolicyActor($assignedSite, 'View:Layout');
    $otherLayout = (new Layout)->forceFill(['site_id' => $otherSite->getKey()]);

    resolve(PermissionRegistrar::class)->setPermissionsTeamId($otherSite->getKey());
    resolve(PermissionRegistrar::class)->forgetCachedPermissions();

    expect((new LayoutPolicy)->view($user, $otherLayout))->toBeFalse();
});

it('denies Media view for a direct record belonging to another site', function (): void {
    $assignedSite = Site::factory()->createOne();
    $otherSite = Site::factory()->createOne();
    $user = cap0532CorePolicyActor($assignedSite, 'View:Media');
    $otherMedia = (new Media)->forceFill(['model_type' => Site::class, 'model_id' => $otherSite->getKey()]);
    $otherMedia->setRelation('model', $otherSite);

    resolve(PermissionRegistrar::class)->setPermissionsTeamId($otherSite->getKey());
    resolve(PermissionRegistrar::class)->forgetCachedPermissions();

    expect((new MediaPolicy)->view($user, $otherMedia))->toBeFalse();
});

it('denies Redirect view for a direct record belonging to another site', function (): void {
    $assignedSite = Site::factory()->createOne();
    $otherSite = Site::factory()->createOne();
    $user = cap0532CorePolicyActor($assignedSite, 'View:PageUrl');
    $otherRedirect = (new PageUrl)->forceFill(['site_id' => $otherSite->getKey()]);
    $otherRedirect->setRelation('site', $otherSite);

    resolve(PermissionRegistrar::class)->setPermissionsTeamId($otherSite->getKey());
    resolve(PermissionRegistrar::class)->forgetCachedPermissions();

    expect((new RedirectPolicy)->view($user, $otherRedirect))->toBeFalse();
});

it('denies Site view for a direct record belonging to another site', function (): void {
    $assignedSite = Site::factory()->createOne();
    $otherSite = Site::factory()->createOne();
    $user = cap0532CorePolicyActor($assignedSite, 'View:Site');

    resolve(PermissionRegistrar::class)->setPermissionsTeamId($otherSite->getKey());
    resolve(PermissionRegistrar::class)->forgetCachedPermissions();

    expect((new SitePolicy)->view($user, $otherSite))->toBeFalse();
});

it('does not borrow active site permissions for a direct Site record', function (): void {
    $assignedSite = Site::factory()->createOne();
    $otherSite = Site::factory()->createOne();
    $user = cap0532CorePolicyActor($assignedSite, 'Update:Site');

    expect((new SitePolicy)->update($user, $otherSite))->toBeFalse()
        ->and((new SitePolicy)->update($user, $assignedSite))->toBeTrue();
});
