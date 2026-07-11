<?php

declare(strict_types=1);

use Capell\Admin\Actions\EnsureCapellPermissionsAction;
use Capell\Admin\Actions\GrantCapellDefaultRolePermissionsAction;
use Capell\Admin\Enums\CapellPermission;
use Capell\Admin\Enums\PermissionSyncMode;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function (): void {
    EnsureCapellPermissionsAction::run();
});

it('grants install defaults to built-in roles', function (): void {
    GrantCapellDefaultRolePermissionsAction::run(PermissionSyncMode::Install);

    expect(Role::findByName('admin')->hasPermissionTo(CapellPermission::ManageSitePermissions->name(), 'web'))->toBeTrue()
        ->and(Role::findByName('admin')->hasPermissionTo(CapellPermission::ManagePageRestrictions->name(), 'web'))->toBeTrue()
        ->and(Role::findByName('admin')->hasPermissionTo(CapellPermission::ExportSite->name(), 'web'))->toBeFalse()
        ->and(Role::findByName('super_admin')->hasPermissionTo(CapellPermission::ExportSite->name(), 'web'))->toBeTrue()
        ->and(Role::findByName('editor')->hasPermissionTo(CapellPermission::ManageSitePermissions->name(), 'web'))->toBeFalse();
});

it('adds upgrade defaults without removing existing role permissions', function (): void {
    $customPermission = Permission::findOrCreate('custom.client.permission');
    $adminRole = Role::findOrCreate('admin');
    $adminRole->givePermissionTo($customPermission);

    GrantCapellDefaultRolePermissionsAction::run(PermissionSyncMode::Upgrade);

    $adminRole->refresh();

    expect($adminRole->hasPermissionTo('custom.client.permission', 'web'))->toBeTrue()
        ->and($adminRole->hasPermissionTo(CapellPermission::ManageSitePermissions->name(), 'web'))->toBeTrue()
        ->and($adminRole->hasPermissionTo(CapellPermission::ExportSite->name(), 'web'))->toBeFalse();
});

it('creates missing built-in roles when granting defaults', function (): void {
    Role::query()
        ->whereIn('name', ['editor', 'admin', 'super_admin'])
        ->delete();

    expect(Role::query()->where('name', 'editor')->exists())->toBeFalse()
        ->and(Role::query()->where('name', 'admin')->exists())->toBeFalse()
        ->and(Role::query()->where('name', 'super_admin')->exists())->toBeFalse();

    GrantCapellDefaultRolePermissionsAction::run(PermissionSyncMode::Upgrade);

    expect(Role::query()->where('name', 'editor')->exists())->toBeTrue()
        ->and(Role::query()->where('name', 'admin')->exists())->toBeTrue()
        ->and(Role::query()->where('name', 'super_admin')->exists())->toBeTrue();
});

it('forgets cached permissions after granting defaults', function (): void {
    $registrar = resolve(PermissionRegistrar::class);
    $registrar->cacheKey = 'capell-test-role-permissions';

    cache()->forever($registrar->cacheKey, ['stale']);

    GrantCapellDefaultRolePermissionsAction::run(PermissionSyncMode::Install);

    expect(cache()->has($registrar->cacheKey))->toBeFalse();
});
