<?php

declare(strict_types=1);

use Capell\Admin\Support\SiteScope;
use Capell\Admin\Tests\Fixtures\Models\RealSiteScopedAdminResourceUser;
use Capell\Core\Models\Site;
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

it('recognises only the site carried by a team-backed role assignment', function (): void {
    $assignedSite = Site::factory()->create();
    $otherSite = Site::factory()->create();
    $user = new RealSiteScopedAdminResourceUser;
    $user->forceFill([
        'name' => 'Site Scoped Admin',
        'email' => fake()->unique()->safeEmail(),
        'password' => bcrypt('password'),
    ]);
    $user->save();

    $role = Role::query()->firstOrCreate([
        'name' => 'site-scope-test-role',
        'guard_name' => 'web',
    ]);

    $user->assignRoleForSite($assignedSite, $role);
    resolve(PermissionRegistrar::class)->setPermissionsTeamId($assignedSite->getKey());

    expect(SiteScope::actorCanUseSite($user, $assignedSite))->toBeTrue()
        ->and(SiteScope::actorCanUseSite($user, $otherSite))->toBeFalse()
        ->and($user->getAssignedSiteIds()->all())->toBe([$assignedSite->getKey()]);
});
