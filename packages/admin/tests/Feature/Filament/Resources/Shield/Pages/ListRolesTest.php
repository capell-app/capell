<?php

declare(strict_types=1);

use Capell\Admin\Filament\Resources\Roles\Pages\ListRoles;
use Capell\Admin\Filament\Resources\Roles\RoleResource;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(CreatesAdminUser::class);

beforeEach(function (): void {
    test()->actingAsAdmin();
});

it('links roles to the permission editing form from a row action', function (): void {
    $role = Role::findOrCreate('content_manager', 'web');

    Livewire::test(ListRoles::class)
        ->assertSuccessful()
        ->assertTableActionVisible('updatePermissions', record: $role)
        ->assertTableActionHasUrl('updatePermissions', RoleResource::getUrl('edit', ['record' => $role]), record: $role);
});

it('uses clearer copy for the built-in select all permissions toggle', function (): void {
    $component = RoleResource::getSelectAllFormComponent();

    expect($component->getLabel())->toBe(__('capell-admin::generic.role_select_all_permissions'));
});

it('uses Capell empty state copy for missing roles', function (): void {
    Role::query()->delete();
    Permission::findOrCreate('view_any_role');
    test()->actingAs(test()->createUserWithPermission('view_any_role'));

    Livewire::test(ListRoles::class)
        ->assertSuccessful()
        ->assertSee(__('capell-admin::generic.no_roles'))
        ->assertSee(__('capell-admin::generic.no_roles_description'));
});

it('hides the update permissions action from users who cannot update roles', function (): void {
    Permission::findOrCreate('view_any_role');
    $role = Role::findOrCreate('content_manager', 'web');

    test()->actingAs(test()->createUserWithPermission('view_any_role'));

    Livewire::test(ListRoles::class)
        ->assertSuccessful()
        ->assertTableActionHidden('updatePermissions', record: $role);
});
