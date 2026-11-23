<?php

declare(strict_types=1);

namespace Capell\Admin\Listeners;

use BezhanSalleh\FilamentShield\Support\Utils;
use Capell\Admin\Actions\Users\GrantPanelAccessAction;
use Capell\Core\Events\InstallUserCreated;
use Illuminate\Database\Eloquent\Model;

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

        // Super admins are admitted to the panel by their own role.
        if (in_array($event->roleName, [null, '', Utils::getSuperAdminName(), config('capell.roles.super_admin')], true)) {
            return;
        }

        GrantPanelAccessAction::run($user, 'web');
    }
}
