<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Users;

use BezhanSalleh\FilamentShield\Support\Utils;
use Capell\Core\Support\Permissions\PermissionTeamContext;
use Illuminate\Database\Eloquent\Model;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use Spatie\Permission\Models\Role;

/**
 * Admits one deliberately created user to the admin panel by giving them Shield's panel user
 * role. Shield's own assign-on-create hook is off by default because it admits every user
 * created in a web request; invitations, the installer and the Users resource call this
 * instead, so only people an administrator chose are admitted.
 */
final class GrantPanelAccessAction
{
    use AsFake;
    use AsObject;

    public function handle(Model $user, ?string $guard = null): void
    {
        if (! method_exists($user, 'assignRole') || ! method_exists($user, 'hasRole')) {
            return;
        }

        $roleName = Utils::getPanelUserRoleName();

        // The panel user role is global, even when site roles are team scoped.
        PermissionTeamContext::run(null, function () use ($user, $roleName, $guard): void {
            if ($user->hasRole($roleName)) {
                return;
            }

            $user->assignRole(Role::findOrCreate($roleName, $guard ?? (string) config('auth.defaults.guard', 'web')));
        }, $user);
    }
}
