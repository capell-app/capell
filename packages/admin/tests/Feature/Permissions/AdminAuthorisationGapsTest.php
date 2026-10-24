<?php

declare(strict_types=1);

use Capell\Admin\Filament\Resources\Layouts\LayoutResource;
use Capell\Admin\Filament\Resources\Sites\SiteResource;
use Capell\Admin\Filament\Resources\Users\Pages\ListUsers;
use Capell\Admin\Filament\Resources\Users\UserResource;
use Capell\Admin\Tests\Fixtures\Models\RealSiteScopedAdminResourceUser;
use Capell\Core\Models\Site;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function (): void {
    config(['permission.teams' => true, 'auth.providers.users.model' => RealSiteScopedAdminResourceUser::class]);
    resolve(PermissionRegistrar::class)->teams = true;
});

afterEach(function (): void {
    auth()->logout();
    resolve(PermissionRegistrar::class)->setPermissionsTeamId(null);
    resolve(PermissionRegistrar::class)->teams = false;
    resolve(PermissionRegistrar::class)->forgetCachedPermissions();
});

/** @param list<string> $permissions */
function authorisationGapUser(Site $site, array $permissions = []): RealSiteScopedAdminResourceUser
{
    resolve(PermissionRegistrar::class)->setPermissionsTeamId($site->id);
    $role = Role::query()->create(['name' => fake()->unique()->uuid(), 'guard_name' => 'web']);
    foreach ($permissions as $permission) {
        $role->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }

    $user = new RealSiteScopedAdminResourceUser;
    $user->forceFill(['name' => 'Site user', 'email' => fake()->unique()->safeEmail(), 'password' => bcrypt('password')])->save();
    $user->assignRoleForSite($site, $role);

    return $user->refresh();
}

it('limits user record resolution and bulk deletion to the active site and preserves the actor', function (): void {
    $site = Site::factory()->create();
    $foreignSite = Site::factory()->create();
    $actor = authorisationGapUser($site, ['ViewAny:User', 'DeleteAny:User', 'Delete:User']);
    $local = authorisationGapUser($site);
    $foreign = authorisationGapUser($foreignSite);
    resolve(PermissionRegistrar::class)->setPermissionsTeamId($site->id);
    test()->actingAs($actor);

    expect(UserResource::getEloquentQuery()->whereKey($foreign->id)->exists())->toBeFalse()
        ->and(UserResource::getGlobalSearchEloquentQuery()->whereKey($foreign->id)->exists())->toBeFalse();

    Livewire::test(ListUsers::class)
        ->assertCanSeeTableRecords([$actor, $local])
        ->assertCanNotSeeTableRecords([$foreign])
        ->callTableBulkAction('delete', [$actor, $local, $foreign]);

    expect($actor->fresh())->not->toBeNull()
        ->and($local->fresh())->toBeNull()
        ->and($foreign->fresh())->not->toBeNull();
});

it('requires explicit replication and creation permissions to replicate a site', function (): void {
    $site = Site::factory()->create();
    $actor = authorisationGapUser($site);
    test()->actingAs($actor);

    expect(SiteResource::canReplicate($site))->toBeFalse();
    $actor->givePermissionTo(Permission::findOrCreate('Replicate:Site', 'web'));
    expect(SiteResource::canReplicate($site))->toBeFalse();
    $actor->givePermissionTo(Permission::findOrCreate('Create:Site', 'web'));
    expect(SiteResource::canReplicate($site))->toBeTrue()
        ->and(SiteResource::canReplicate(Site::factory()->create()))->toBeFalse();
});

it('requires bulk restore and force delete permissions for sites and layouts', function (string $resource, string $subject): void {
    $site = Site::factory()->create();
    $actor = authorisationGapUser($site);
    test()->actingAs($actor);

    expect($resource::canRestoreAny())->toBeFalse()
        ->and($resource::canForceDeleteAny())->toBeFalse();
    $actor->givePermissionTo(Permission::findOrCreate('RestoreAny:' . $subject, 'web'));
    expect($resource::canRestoreAny())->toBeTrue()
        ->and($resource::canForceDeleteAny())->toBeFalse();
    $actor->givePermissionTo(Permission::findOrCreate('ForceDeleteAny:' . $subject, 'web'));
    expect($resource::canForceDeleteAny())->toBeTrue();
})->with([[SiteResource::class, 'Site'], [LayoutResource::class, 'Layout']]);

it('preserves global user visibility and fails closed without an authenticated actor', function (): void {
    $site = Site::factory()->create();
    $local = authorisationGapUser($site);
    resolve(PermissionRegistrar::class)->setPermissionsTeamId(null);
    $admin = authorisationGapUser($site);
    resolve(PermissionRegistrar::class)->setPermissionsTeamId(null);
    $admin->assignRole(Role::findOrCreate('super_admin', 'web'));
    test()->actingAs($admin);

    expect(UserResource::getEloquentQuery()->whereKey($local->id)->exists())->toBeTrue();
    auth()->logout();
    expect(UserResource::getEloquentQuery()->exists())->toBeFalse();
});
