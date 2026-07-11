<?php

declare(strict_types=1);

use Capell\Admin\Enums\CapellPermission;
use Capell\Admin\Enums\ResourceEnum;

it('resolves all Capell custom permission names for default Shield config', function (): void {
    config()->set('filament-shield.permissions.case', 'pascal');
    config()->set('filament-shield.permissions.separator', ':');

    expect(CapellPermission::names())->toBe([
        'impersonate_users',
        'Manage:ExtensionsPage',
        'upgrade.run',
        'page.export',
        'site.export',
        'UpdateOwn:Site',
        'ManagePermissions:Site',
        'ManageRestrictions:Page',
        'presentation.manage_advanced',
        'activity_log.revert',
        'activity_log.delete',
        'page.rollback',
    ]);
});

it('resolves Shield-backed permissions using legacy snake case config', function (): void {
    config()->set('filament-shield.permissions.case', 'lower_snake');
    config()->set('filament-shield.permissions.separator', '_');

    expect(CapellPermission::UpdateOwnSite->name())->toBe('update_own_site')
        ->and(CapellPermission::ManageSitePermissions->name())->toBe('manage_permissions_site')
        ->and(CapellPermission::ManagePageRestrictions->name())->toBe('manage_restrictions_page');
});

it('resolves resource permissions through the resource enum', function (): void {
    config()->set('filament-shield.permissions.case', 'pascal');
    config()->set('filament-shield.permissions.separator', ':');

    expect(ResourceEnum::PageUrl->permission('view_any'))->toBe('ViewAny:PageUrl');
});

it('defines install and upgrade grants separately', function (): void {
    expect(CapellPermission::ManageSitePermissions->installRoles())->toBe(['admin', 'super_admin'])
        ->and(CapellPermission::ManageSitePermissions->upgradeRoles())->toBe(['admin', 'super_admin'])
        ->and(CapellPermission::ImpersonateUsers->installRoles())->toBe(['admin', 'super_admin'])
        ->and(CapellPermission::ImpersonateUsers->upgradeRoles())->toBe(['super_admin'])
        ->and(CapellPermission::RunUpgrades->installRoles())->toBe(['super_admin'])
        ->and(CapellPermission::RunUpgrades->upgradeRoles())->toBe(['super_admin'])
        ->and(CapellPermission::ExportPage->installRoles())->toBe(['super_admin'])
        ->and(CapellPermission::ExportPage->upgradeRoles())->toBe(['super_admin'])
        ->and(CapellPermission::ManageAdvancedPresentationSettings->installRoles())->toBe(['super_admin'])
        ->and(CapellPermission::ManageAdvancedPresentationSettings->upgradeRoles())->toBe(['super_admin'])
        ->and(CapellPermission::RevertActivityLog->installRoles())->toBe(['super_admin'])
        ->and(CapellPermission::RevertActivityLog->upgradeRoles())->toBe(['super_admin'])
        ->and(CapellPermission::DeleteActivityLog->installRoles())->toBe(['super_admin'])
        ->and(CapellPermission::DeleteActivityLog->upgradeRoles())->toBe(['super_admin'])
        ->and(CapellPermission::RollbackPage->installRoles())->toBe(['admin', 'super_admin'])
        ->and(CapellPermission::RollbackPage->upgradeRoles())->toBe(['admin', 'super_admin']);
});
