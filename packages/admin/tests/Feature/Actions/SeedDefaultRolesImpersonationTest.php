<?php

declare(strict_types=1);

use Capell\Admin\Actions\EnsureCapellPermissionsAction;
use Capell\Admin\Actions\SeedDefaultRolesAction;
use Capell\Admin\Enums\CapellPermission;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    EnsureCapellPermissionsAction::run();
});

it('grants impersonate_users to the admin role', function (): void {
    SeedDefaultRolesAction::run();

    $role = Role::findByName('admin');

    expect(
        $role->hasPermissionTo(CapellPermission::ImpersonateUsers->name(), 'web'),
    )->toBeTrue();
});

it('creates the panel user role needed for non-super admin panel access', function (): void {
    config()->set('filament-shield.panel_user.enabled', true);
    config()->set('filament-shield.panel_user.name', 'panel_user');

    SeedDefaultRolesAction::run();

    expect(Role::query()->where('name', 'panel_user')->exists())->toBeTrue();
});

it('grants Capell custom management permissions to the admin role', function (): void {
    SeedDefaultRolesAction::run();

    $role = Role::findByName('admin');

    expect($role->hasPermissionTo(CapellPermission::ManageSitePermissions->name(), 'web'))->toBeTrue()
        ->and($role->hasPermissionTo(CapellPermission::ManagePageRestrictions->name(), 'web'))->toBeTrue()
        ->and($role->hasPermissionTo(CapellPermission::UpdateOwnSite->name(), 'web'))->toBeTrue();
});

it('does not grant impersonate_users to the editor role', function (): void {
    SeedDefaultRolesAction::run();

    $role = Role::findByName('editor');

    expect(
        $role->hasPermissionTo(CapellPermission::ImpersonateUsers->name(), 'web'),
    )->toBeFalse();
});

it('grants impersonate_users to super_admin via Permission::all()', function (): void {
    SeedDefaultRolesAction::run();

    $role = Role::findByName('super_admin');

    expect(
        $role->hasPermissionTo(CapellPermission::ImpersonateUsers->name(), 'web'),
    )->toBeTrue();
});
