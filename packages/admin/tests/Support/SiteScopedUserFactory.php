<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Support;

use Capell\Admin\Tests\Fixtures\Models\RealSiteScopedAdminResourceUser;
use Capell\Core\Models\Site;
use Capell\Core\Support\Permissions\PermissionTeamContext;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

final class SiteScopedUserFactory
{
    /** @param list<string> $permissions */
    public static function create(Site $site, array $permissions = []): RealSiteScopedAdminResourceUser
    {
        return PermissionTeamContext::run($site->getKey(), static function () use ($site, $permissions): RealSiteScopedAdminResourceUser {
            $user = new RealSiteScopedAdminResourceUser;
            $user->forceFill([
                'name' => 'Site user',
                'email' => fake()->unique()->safeEmail(),
                'password' => bcrypt('password'),
            ])->save();
            Role::findOrCreate('test-site-member', 'web');
            $user->assignRoleForSite($site, 'test-site-member');

            foreach ($permissions as $permission) {
                $user->givePermissionTo(Permission::findOrCreate($permission, 'web'));
            }

            // Session logout reads database defaults such as remember_token under strict model access.
            return $user->refresh();
        });
    }
}
