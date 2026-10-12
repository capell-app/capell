<?php

declare(strict_types=1);

use Capell\Admin\Actions\SyncCapellPermissionsAction;
use Capell\Admin\Data\AdminSurfaceContributionData;
use Capell\Admin\Enums\CapellPermission;
use Capell\Admin\Enums\PermissionSyncMode;
use Capell\Admin\Facades\CapellAdmin;
use Capell\Admin\Filament\Pages\SiteHealthPage;
use Capell\Admin\Filament\Resources\Roles\RoleResource;
use Capell\Core\Enums\ExtensionStatusEnum;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\CapellExtension;
use Capell\Core\Support\Manifest\CapellManifestData;
use Capell\Marketplace\Filament\Pages\MarketplacePage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

it('syncs enabled manifest permissions with the existing administrator grant policy', function (PermissionSyncMode $mode): void {
    config()->set('filament-shield.super_admin.define_via_gate', false);
    $manifest = CapellManifestData::fromArray(capellManifestV3Array(
        name: 'vendor/redirects',
        overrides: ['permissions' => ['Manage:RedirectRules', 'Manage:RedirectRules']],
    ));
    CapellCore::registerManifestPackage($manifest);
    CapellCore::forcePackageInstalled($manifest->name);
    $superAdmin = Role::findOrCreate('super_admin', 'web');
    $customPermission = Permission::findOrCreate('Custom:Existing', 'web');
    $superAdmin->givePermissionTo($customPermission);
    $otherGuard = Role::findOrCreate('super_admin', 'api');

    SyncCapellPermissionsAction::run($mode);
    SyncCapellPermissionsAction::run($mode);

    expect(Permission::query()->where('name', 'Manage:RedirectRules')->where('guard_name', 'web')->count())->toBe(1)
        ->and($superAdmin->refresh()->hasPermissionTo('Manage:RedirectRules', 'web'))->toBeTrue()
        ->and($superAdmin->hasPermissionTo('Custom:Existing', 'web'))->toBeTrue()
        ->and(Role::findByName('admin', 'web')->hasPermissionTo('Manage:RedirectRules', 'web'))->toBeFalse()
        ->and(Role::findByName('editor', 'web')->hasPermissionTo('Manage:RedirectRules', 'web'))->toBeFalse()
        ->and($otherGuard->refresh()->permissions()->count())->toBe(0);
})->with([PermissionSyncMode::Install, PermissionSyncMode::Upgrade]);

it('does not materialise permissions from inactive manifests', function (?ExtensionStatusEnum $status): void {
    $manifest = CapellManifestData::fromArray(capellManifestV3Array(
        name: 'vendor/inactive',
        overrides: ['permissions' => ['Manage:InactivePackage']],
    ));
    CapellCore::registerManifestPackage($manifest);
    if ($status instanceof ExtensionStatusEnum) {
        CapellExtension::query()->create([
            'composer_name' => $manifest->name,
            'name' => 'Inactive package',
            'version' => '1.0.0',
            'status' => $status,
            'installed_at' => now(),
        ]);
        CapellCore::forcePackageInstalled($manifest->name);
    }

    SyncCapellPermissionsAction::run(PermissionSyncMode::Upgrade);

    expect(Permission::query()->where('name', 'Manage:InactivePackage')->exists())->toBeFalse();
})->with([null, ExtensionStatusEnum::Disabled, ExtensionStatusEnum::Failed, ExtensionStatusEnum::Uninstalled]);

it('respects Shield settings when granting manifest permissions during upgrade', function (bool $enabled, bool $viaGate): void {
    config()->set('filament-shield.super_admin.enabled', $enabled);
    config()->set('filament-shield.super_admin.define_via_gate', $viaGate);
    config()->set('filament-shield.super_admin.name', 'package-administrator');

    $manifest = CapellManifestData::fromArray(capellManifestV3Array(
        name: 'vendor/redirects',
        overrides: ['permissions' => ['Manage:RedirectRules']],
    ));
    CapellCore::registerManifestPackage($manifest);
    CapellCore::forcePackageInstalled($manifest->name);
    $role = Role::findOrCreate('package-administrator', 'web');

    SyncCapellPermissionsAction::run(PermissionSyncMode::Upgrade);

    expect(Permission::query()->where('name', 'Manage:RedirectRules')->where('guard_name', 'web')->exists())->toBeTrue()
        ->and($role->refresh()->hasPermissionTo('Manage:RedirectRules', 'web'))->toBe($enabled && ! $viaGate);
})->with([[true, false], [true, true], [false, false]]);

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
        ->and(Role::findByName('editor')->hasPermissionTo('ViewAny:PageUrl', 'web'))->toBeTrue()
        ->and(Role::findByName('editor')->hasPermissionTo('View:PageUrl', 'web'))->toBeTrue()
        ->and(Role::findByName('editor')->hasPermissionTo('Create:PageUrl', 'web'))->toBeTrue()
        ->and(Role::findByName('editor')->hasPermissionTo(CapellPermission::RollbackPage->name(), 'web'))->toBeTrue()
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
