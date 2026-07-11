<?php

declare(strict_types=1);

use Capell\Admin\Enums\CapellPermission;
use Capell\Admin\Filament\Resources\Sites\Pages\EditSite;
use Capell\Admin\Tests\Fixtures\Models\ManageSitePermissionsActionTestUser;
use Capell\Core\Models\Site;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

function makeManageSitePermissionsActionTestUser(): ManageSitePermissionsActionTestUser
{
    $user = new ManageSitePermissionsActionTestUser;

    $user->forceFill([
        'name' => 'Manage Site Permissions User',
        'email' => fake()->unique()->safeEmail(),
        'password' => bcrypt('password'),
    ]);
    $user->save();

    return $user;
}

function assignRoleForSiteInDatabase(ManageSitePermissionsActionTestUser $user, Site $site, Role $role): void
{
    DB::table('model_has_roles')->insert([
        'role_id' => $role->getKey(),
        'model_type' => $user->getMorphClass(),
        'model_id' => $user->getKey(),
        'team_id' => $site->getKey(),
    ]);
}

beforeEach(function (): void {
    config()->set('auth.providers.users.model', ManageSitePermissionsActionTestUser::class);

    Permission::findOrCreate(CapellPermission::ManageSitePermissions->name());
    Permission::findOrCreate('Update:Site');
    Role::findOrCreate('editor');
    Role::findOrCreate('super_admin');
});

it('shows the manage permissions action to users with the custom permission', function (): void {
    $site = Site::factory()->createOne();
    $siteUser = makeManageSitePermissionsActionTestUser();
    /** @var Role $editorRole */
    $editorRole = Role::findOrCreate('editor');

    $siteUser->givePermissionTo([
        CapellPermission::ManageSitePermissions->name(),
        'Update:Site',
    ]);
    assignRoleForSiteInDatabase($siteUser, $site, $editorRole);

    test()->actingAs($siteUser);

    Livewire::test(EditSite::class, ['record' => $site->getRouteKey()])
        ->assertSuccessful()
        ->assertActionVisible('manage_site_permissions');
});

it('hides the manage permissions action from users without permission', function (): void {
    $site = Site::factory()->createOne();
    $siteUser = makeManageSitePermissionsActionTestUser();
    /** @var Role $editorRole */
    $editorRole = Role::findOrCreate('editor');

    $siteUser->givePermissionTo('Update:Site');
    assignRoleForSiteInDatabase($siteUser, $site, $editorRole);

    test()->actingAs($siteUser);

    Livewire::test(EditSite::class, ['record' => $site->getRouteKey()])
        ->assertSuccessful()
        ->assertActionHidden('manage_site_permissions');
});

it('syncs site permissions from the edit site action', function (): void {
    $site = Site::factory()->createOne();
    $actor = makeManageSitePermissionsActionTestUser();
    $assignedUser = makeManageSitePermissionsActionTestUser();
    /** @var Role $editorRole */
    $editorRole = Role::findOrCreate('editor');

    $actor->givePermissionTo([CapellPermission::ManageSitePermissions->name(), 'Update:Site']);
    $actor->assignRole('super_admin');

    test()->actingAs($actor);

    Livewire::test(EditSite::class, ['record' => $site->getRouteKey()])
        ->assertSuccessful()
        ->callAction('manage_site_permissions', data: [
            'assignments' => [
                [
                    'user_id' => $assignedUser->getKey(),
                    'role_ids' => [$editorRole->getKey()],
                ],
            ],
        ])
        ->assertHasNoActionErrors();

    expect(DB::table('model_has_roles')
        ->where('model_type', $assignedUser->getMorphClass())
        ->where('model_id', $assignedUser->getKey())
        ->where('role_id', $editorRole->getKey())
        ->where('team_id', $site->getKey())
        ->exists())->toBeTrue();
});

it('hydrates existing site permissions into the modal form', function (): void {
    $site = Site::factory()->createOne();
    $actor = makeManageSitePermissionsActionTestUser();
    $assignedUser = makeManageSitePermissionsActionTestUser();
    /** @var Role $editorRole */
    $editorRole = Role::findOrCreate('editor');

    $actor->givePermissionTo([CapellPermission::ManageSitePermissions->name(), 'Update:Site']);
    $actor->assignRole('super_admin');
    assignRoleForSiteInDatabase($assignedUser, $site, $editorRole);

    test()->actingAs($actor);

    Livewire::test(EditSite::class, ['record' => $site->getRouteKey()])
        ->assertSuccessful()
        ->mountAction('manage_site_permissions')
        ->assertActionDataSet(function (array $state) use ($assignedUser, $editorRole): array {
            /** @var list<array{user_id: mixed, role_ids?: list<mixed>}> $rawAssignments */
            $rawAssignments = $state['assignments'] ?? [];

            $assignments = collect($rawAssignments)
                ->map(fn (array $assignment): array => [
                    'user_id' => (int) $assignment['user_id'],
                    'role_ids' => collect($assignment['role_ids'] ?? [])
                        ->map(fn (mixed $roleId): int => (int) $roleId)
                        ->values()
                        ->all(),
                ])
                ->values()
                ->all();

            expect($assignments)->toBe([
                [
                    'user_id' => $assignedUser->getKey(),
                    'role_ids' => [$editorRole->getKey()],
                ],
            ]);

            return [];
        });
});
