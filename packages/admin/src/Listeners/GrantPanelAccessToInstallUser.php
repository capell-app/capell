<?php

declare(strict_types=1);

namespace Capell\Admin\Listeners;

use BezhanSalleh\FilamentShield\Support\Utils;
use Capell\Core\Events\InstallUserCreated;
use Capell\Core\Support\Permissions\PermissionTeamContext;
use Illuminate\Database\Eloquent\Model;
use Spatie\Permission\Models\Role;

/**
 * Shield only admits people to the panel with the super admin or panel user
 * role. Starter accounts from the installer get a Capell role, so they also
 * need the panel user role or they cannot sign in.
 */
final class GrantPanelAccessToInstallUser
{
    public function handle(InstallUserCreated $event): void
    {
        $user = $event->user;

        if (! $user instanceof Model || ! method_exists($user, 'assignRole') || ! method_exists($user, 'hasRole')) {
            return;
        }

        if (! Utils::isPanelUserRoleEnabled()) {
            return;
        }

        // Super admins are admitted to the panel by their own role.
        if (in_array($event->roleName, [null, '', Utils::getSuperAdminName(), config('capell.roles.super_admin')], true)) {
            return;
        }

        $panelUserRoleName = Utils::getPanelUserRoleName();

        // The panel user role is global, even when site roles are team scoped.
        PermissionTeamContext::run(null, function () use ($user, $panelUserRoleName): void {
            if ($user->hasRole($panelUserRoleName)) {
                return;
            }

            $user->assignRole(Role::findOrCreate($panelUserRoleName, 'web'));
        }, $user);
    }
}
