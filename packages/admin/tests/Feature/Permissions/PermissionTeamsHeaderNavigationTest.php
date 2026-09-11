<?php

declare(strict_types=1);

use Capell\Admin\Support\HeaderNavigation\HeaderNavigationAccessResolver;
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
    resolve(PermissionRegistrar::class)->setPermissionsTeamId(null);
    resolve(PermissionRegistrar::class)->teams = false;
    resolve(PermissionRegistrar::class)->forgetCachedPermissions();
    config(['permission.teams' => false]);
});

it('does not reuse a loaded permission relation while checking another site', function (): void {
    $firstSite = Site::factory()->createOne();
    $secondSite = Site::factory()->createOne();
    $permission = Permission::findOrCreate('ViewAny:Page', 'web');
    $firstSiteRole = Role::query()->firstOrCreate([
        'name' => 'header-navigation-first-site-editor',
        'guard_name' => 'web',
    ]);
    $firstSiteRole->givePermissionTo($permission);

    $secondSiteRole = Role::query()->firstOrCreate([
        'name' => 'header-navigation-second-site-member',
        'guard_name' => 'web',
    ]);

    $user = RealSiteScopedAdminResourceUser::query()->create([
        'name' => 'Header navigation scoped user',
        'email' => fake()->unique()->safeEmail(),
        'password' => bcrypt('password'),
    ]);
    $user->assignRoleForSite($firstSite, $firstSiteRole);
    $user->assignRoleForSite($secondSite, $secondSiteRole);

    $resolver = new HeaderNavigationAccessResolver;

    expect($resolver->canViewAnyPagesForSite($user, $firstSite))->toBeTrue()
        ->and($resolver->canViewAnyPagesForSite($user, $secondSite))->toBeFalse()
        ->and($resolver->canViewAnyPagesForSite($user, $firstSite))->toBeTrue()
        ->and(resolve(PermissionRegistrar::class)->getPermissionsTeamId())->toBeNull();
});
