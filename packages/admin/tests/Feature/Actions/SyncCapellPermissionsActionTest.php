<?php

declare(strict_types=1);

use Capell\Admin\Actions\SyncCapellPermissionsAction;
use Capell\Admin\Data\AdminSurfaceContributionData;
use Capell\Admin\Enums\CapellPermission;
use Capell\Admin\Enums\PermissionSyncMode;
use Capell\Admin\Facades\CapellAdmin;
use Capell\Admin\Filament\Pages\SiteHealthPage;
use Capell\Admin\Filament\Resources\Roles\RoleResource;
use Capell\Marketplace\Filament\Pages\MarketplacePage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

it('syncs generated and Capell catalog permissions during install', function (): void {
    SyncCapellPermissionsAction::run(PermissionSyncMode::Install);

    expect(Permission::query()->pluck('name')->all())->toContain(
        'ViewAny:Page',
        'Update:Page',
        'ViewAny:Site',
        'ViewAny:Role',
        CapellPermission::ImpersonateUsers->name(),
        CapellPermission::ExportPage->name(),
        CapellPermission::ExportSite->name(),
        CapellPermission::UpdateOwnSite->name(),
        CapellPermission::ManageSitePermissions->name(),
        CapellPermission::ManagePageRestrictions->name(),
        'View:' . class_basename(SiteHealthPage::class),
    );
});

it('seeds install role defaults', function (): void {
    SyncCapellPermissionsAction::run(PermissionSyncMode::Install);

    expect(Role::findByName('editor')->hasPermissionTo('Update:Page', 'web'))->toBeTrue()
        ->and(Role::findByName('admin')->hasPermissionTo(CapellPermission::ManageSitePermissions->name(), 'web'))->toBeTrue()
        ->and(Role::findByName('admin')->hasPermissionTo(CapellPermission::ExportSite->name(), 'web'))->toBeFalse()
        ->and(Role::findByName('super_admin')->hasPermissionTo(CapellPermission::ExportSite->name(), 'web'))->toBeTrue()
        ->and(Role::findByName('super_admin')->hasPermissionTo('ViewAny:Role', 'web'))->toBeTrue()
        ->and(Role::findByName('super_admin')->hasPermissionTo('View:' . class_basename(SiteHealthPage::class), 'web'))->toBeTrue();
});

it('seeds install permissions for contributed package pages', function (): void {
    CapellAdmin::contributeToAdminSurface(AdminSurfaceContributionData::page(MarketplacePage::class));

    SyncCapellPermissionsAction::run(PermissionSyncMode::Install);

    $marketplacePermissionName = 'View:' . class_basename(MarketplacePage::class);

    expect(Permission::query()->where('name', $marketplacePermissionName)->exists())->toBeTrue()
        ->and(Role::findByName('super_admin')->hasPermissionTo($marketplacePermissionName, 'web'))->toBeTrue();
});

it('seeds install permissions for contributed package resources', function (): void {
    CapellAdmin::contributeToAdminSurface(AdminSurfaceContributionData::resource(
        RoleResource::class,
        'Shield',
    ));

    SyncCapellPermissionsAction::run(PermissionSyncMode::Install);

    expect(Permission::query()->where('name', 'ViewAny:Role')->exists())->toBeTrue()
        ->and(Permission::query()->where('name', 'Create:Role')->exists())->toBeTrue()
        ->and(Role::findByName('super_admin')->hasPermissionTo('ViewAny:Role', 'web'))->toBeTrue();
});

it('preserves existing custom role permissions during upgrade sync', function (): void {
    $customPermission = Permission::findOrCreate('custom.client.permission');
    $adminRole = Role::findOrCreate('admin');
    $adminRole->givePermissionTo($customPermission);

    SyncCapellPermissionsAction::run(PermissionSyncMode::Upgrade);

    $adminRole->refresh();

    expect($adminRole->hasPermissionTo('custom.client.permission', 'web'))->toBeTrue()
        ->and($adminRole->hasPermissionTo(CapellPermission::ManageSitePermissions->name(), 'web'))->toBeTrue()
        ->and($adminRole->hasPermissionTo(CapellPermission::ExportSite->name(), 'web'))->toBeFalse();
});

it('is idempotent', function (): void {
    SyncCapellPermissionsAction::run(PermissionSyncMode::Install);
    SyncCapellPermissionsAction::run(PermissionSyncMode::Install);

    expect(Permission::query()->where('name', CapellPermission::ManageSitePermissions->name())->count())->toBe(1)
        ->and(Permission::query()->where('name', CapellPermission::ImpersonateUsers->name())->count())->toBe(1);
});
