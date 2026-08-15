<?php

declare(strict_types=1);

use Capell\Admin\Actions\EnsureCapellPermissionsAction;
use Capell\Admin\Actions\GrantCapellDefaultRolePermissionsAction;
use Capell\Admin\Enums\CapellPermission;
use Capell\Admin\Enums\PermissionSyncMode;
use Capell\Admin\Enums\ResourceEnum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function (): void {
    EnsureCapellPermissionsAction::run();
});

it('grants install defaults to built-in roles', function (): void {
    GrantCapellDefaultRolePermissionsAction::run(PermissionSyncMode::Install);

    $editorRole = Role::findByName('editor');
    $adminRole = Role::findByName('admin');
    $superRole = Role::findByName('super_admin');

    expect($adminRole->hasPermissionTo(CapellPermission::ManageSitePermissions->name(), 'web'))->toBeTrue()
        ->and($adminRole->hasPermissionTo(CapellPermission::ManagePageRestrictions->name(), 'web'))->toBeTrue()
        ->and($adminRole->hasPermissionTo(CapellPermission::ExportSite->name(), 'web'))->toBeFalse()
        ->and($superRole->hasPermissionTo(CapellPermission::ExportSite->name(), 'web'))->toBeTrue()
        ->and($editorRole->hasPermissionTo(CapellPermission::RollbackPage->name(), 'web'))->toBeTrue()
        ->and($editorRole->hasPermissionTo(ResourceEnum::PageUrl->permission('view_any'), 'web'))->toBeTrue()
        ->and($editorRole->hasPermissionTo(ResourceEnum::PageUrl->permission('view'), 'web'))->toBeTrue()
        ->and($editorRole->hasPermissionTo(ResourceEnum::PageUrl->permission('create'), 'web'))->toBeTrue()
        ->and($editorRole->hasPermissionTo(ResourceEnum::PageUrl->permission('update'), 'web'))->toBeTrue()
        ->and($editorRole->hasPermissionTo(CapellPermission::ManageSitePermissions->name(), 'web'))->toBeFalse();
});

it('adds upgrade defaults without removing existing role permissions', function (): void {
    $customPermission = Permission::findOrCreate('custom.client.permission');
    $adminRole = Role::findOrCreate('admin');
    $adminRole->givePermissionTo($customPermission);

    GrantCapellDefaultRolePermissionsAction::run(PermissionSyncMode::Upgrade);

    $adminRole->refresh();
    $editorRole = Role::findByName('editor');

    expect($adminRole->hasPermissionTo('custom.client.permission', 'web'))->toBeTrue()
        ->and($adminRole->hasPermissionTo(CapellPermission::ManageSitePermissions->name(), 'web'))->toBeTrue()
        ->and($adminRole->hasPermissionTo(CapellPermission::ExportSite->name(), 'web'))->toBeFalse()
        ->and($editorRole->hasPermissionTo(ResourceEnum::PageUrl->permission('view_any'), 'web'))->toBeTrue()
        ->and($editorRole->hasPermissionTo(ResourceEnum::PageUrl->permission('view'), 'web'))->toBeTrue()
        ->and($editorRole->hasPermissionTo(ResourceEnum::PageUrl->permission('create'), 'web'))->toBeTrue()
        ->and($editorRole->hasPermissionTo(ResourceEnum::PageUrl->permission('update'), 'web'))->toBeTrue()
        ->and($editorRole->hasPermissionTo(CapellPermission::RollbackPage->name(), 'web'))->toBeTrue();
});

it('backfills editor page URL permissions during an upgrade', function (): void {
    foreach (['view_any', 'view', 'create', 'update'] as $affix) {
        Permission::create([
            'name' => ResourceEnum::PageUrl->permission($affix),
            'guard_name' => 'web',
        ]);
    }

    GrantCapellDefaultRolePermissionsAction::run(PermissionSyncMode::Upgrade);

    $editorRole = Role::findByName('editor');

    expect($editorRole->hasPermissionTo('ViewAny:PageUrl', 'web'))->toBeTrue()
        ->and($editorRole->hasPermissionTo('View:PageUrl', 'web'))->toBeTrue()
        ->and($editorRole->hasPermissionTo('Create:PageUrl', 'web'))->toBeTrue()
        ->and($editorRole->hasPermissionTo('Update:PageUrl', 'web'))->toBeTrue()
        ->and($editorRole->hasPermissionTo(CapellPermission::RollbackPage->name(), 'web'))->toBeTrue();
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
