<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Shield;

use Capell\Admin\Enums\CapellPermission;
use Capell\Admin\Policies\Concerns\ResolvesShieldPermission;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsObject;
use Spatie\Permission\Models\Permission;

class ResolveDefaultRolePermissionsAction
{
    use AsObject;
    use ResolvesShieldPermission;

    /**
     * @return list<string>
     */
    public function handle(string $roleName, string $guardName): array
    {
        return match ($roleName) {
            'editor' => $this->existing([
                self::permission('view_any', 'Page'),
                self::permission('view', 'Page'),
                self::permission('create', 'Page'),
                self::permission('edit_content', 'Page'),
                self::permission('edit_layout', 'Page'),
                self::permission('update', 'Page'),
                self::permission('view_any', 'Navigation'),
                self::permission('view', 'Navigation'),
                self::permission('view_any', 'Media'),
                self::permission('view', 'Media'),
                self::permission('create', 'Media'),
                self::permission('update', 'Media'),
            ], $guardName),
            'admin' => $this->existing([
                self::permission('view_any', 'Page'),
                self::permission('view', 'Page'),
                self::permission('create', 'Page'),
                self::permission('edit_content', 'Page'),
                self::permission('edit_layout', 'Page'),
                self::permission('update', 'Page'),
                self::permission('delete', 'Page'),
                self::permission('restore', 'Page'),
                CapellPermission::ManagePageRestrictions->name(),
                CapellPermission::RollbackPage->name(),
                CapellPermission::ImpersonateUsers->name(),
                self::permission('view_any', 'Navigation'),
                self::permission('view', 'Navigation'),
                self::permission('create', 'Navigation'),
                self::permission('update', 'Navigation'),
                self::permission('delete', 'Navigation'),
                self::permission('view_any', 'Site'),
                self::permission('view', 'Site'),
                self::permission('update', 'Site'),
                CapellPermission::UpdateOwnSite->name(),
                CapellPermission::ManageSitePermissions->name(),
                self::permission('view_any', 'Media'),
                self::permission('view', 'Media'),
                self::permission('create', 'Media'),
                self::permission('update', 'Media'),
                self::permission('delete', 'Media'),
                self::permission('view_any', 'Redirect'),
                self::permission('view', 'Redirect'),
                self::permission('create', 'Redirect'),
                self::permission('update', 'Redirect'),
                self::permission('delete', 'Redirect'),
            ], $guardName),
            'super_admin' => $this->permissionNamesForGuard($guardName),
            default => throw new InvalidArgumentException(sprintf('Role [%s] does not have Capell defaults.', $roleName)),
        };
    }

    /**
     * @param  list<string>  $permissionNames
     * @return list<string>
     */
    private function existing(array $permissionNames, string $guardName): array
    {
        return array_values(Permission::query()
            ->where('guard_name', $guardName)
            ->whereIn('name', $permissionNames)
            ->pluck('name')
            ->sort()
            ->values()
            ->all());
    }

    /**
     * @return list<string>
     */
    private function permissionNamesForGuard(string $guardName): array
    {
        return array_values(Permission::query()
            ->where('guard_name', $guardName)
            ->pluck('name')
            ->sort()
            ->values()
            ->all());
    }
}
