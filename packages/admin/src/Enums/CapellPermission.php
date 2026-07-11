<?php

declare(strict_types=1);

namespace Capell\Admin\Enums;

use BezhanSalleh\FilamentShield\Facades\FilamentShield;
use BezhanSalleh\FilamentShield\Support\Utils;

enum CapellPermission
{
    case ImpersonateUsers;
    case ManageExtensions;
    case RunUpgrades;
    case ExportPage;
    case ExportSite;
    case UpdateOwnSite;
    case ManageSitePermissions;
    case ManagePageRestrictions;
    case ManageAdvancedPresentationSettings;
    case RevertActivityLog;
    case DeleteActivityLog;
    case RollbackPage;

    // NOTE: A `ManagePageWorkflow` (page.workflow.manage) permission was
    // intentionally NOT added here. The editorial workflow ships backend-only:
    // the event-sourced status is projected and shown as a read-only badge, but
    // there are no editor-facing transition controls yet. Seeding a permission
    // that nothing enforces would surface a capability that does not exist. Add
    // it back together with the transition UI that actually checks it.

    /**
     * @return array<int, string>
     */
    public static function names(): array
    {
        return array_map(
            fn (self $permission): string => $permission->name(),
            self::cases(),
        );
    }

    public function name(): string
    {
        return match ($this) {
            self::ImpersonateUsers => 'impersonate_users',
            self::ManageExtensions => 'Manage:ExtensionsPage',
            self::RunUpgrades => 'upgrade.run',
            self::ExportPage => 'page.export',
            self::ExportSite => 'site.export',
            self::UpdateOwnSite => self::shieldPermission('update_own', 'Site'),
            self::ManageSitePermissions => self::shieldPermission('manage_permissions', 'Site'),
            self::ManagePageRestrictions => self::shieldPermission('manage_restrictions', 'Page'),
            self::ManageAdvancedPresentationSettings => 'presentation.manage_advanced',
            self::RevertActivityLog => 'activity_log.revert',
            self::DeleteActivityLog => 'activity_log.delete',
            self::RollbackPage => 'page.rollback',
        };
    }

    /**
     * @return array<int, string>
     */
    public function installRoles(): array
    {
        return match ($this) {
            self::ImpersonateUsers,
            self::UpdateOwnSite,
            self::ManageSitePermissions,
            self::ManagePageRestrictions => ['admin', 'super_admin'],
            self::ManageExtensions,
            self::RunUpgrades,
            self::ManageAdvancedPresentationSettings,
            self::ExportPage,
            self::ExportSite,
            self::RevertActivityLog,
            self::DeleteActivityLog => ['super_admin'],
            self::RollbackPage => ['admin', 'super_admin'],
        };
    }

    /**
     * @return array<int, string>
     */
    public function upgradeRoles(): array
    {
        return match ($this) {
            self::UpdateOwnSite,
            self::ManageSitePermissions,
            self::ManagePageRestrictions => ['admin', 'super_admin'],
            self::ManageAdvancedPresentationSettings => ['super_admin'],
            self::RollbackPage => ['admin', 'super_admin'],
            default => ['super_admin'],
        };
    }

    private static function shieldPermission(string $affix, string $subject): string
    {
        $shieldConfig = Utils::getConfig();

        return FilamentShield::defaultPermissionKeyBuilder(
            affix: $affix,
            separator: (string) data_get($shieldConfig, 'permissions.separator'),
            subject: $subject,
            case: (string) data_get($shieldConfig, 'permissions.case'),
        );
    }
}
