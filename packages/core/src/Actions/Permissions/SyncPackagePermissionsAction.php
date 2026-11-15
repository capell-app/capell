<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Permissions;

use Capell\Core\Data\PackageData;
use Capell\Core\Support\Permissions\PermissionTeamContext;
use Illuminate\Support\Facades\Schema;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use Spatie\Permission\PermissionRegistrar;

/**
 * Creates the permissions a package's manifest declares and grants them to the super admin role only.
 * Every other role receives them from an administrator. Running it again changes nothing, and a
 * permission already granted elsewhere is left as it is, so installing and enabling can both call it.
 *
 * @method static void run(PackageData $package)
 */
final class SyncPackagePermissionsAction
{
    use AsFake;
    use AsObject;

    public function handle(PackageData $package): void
    {
        $names = array_values(array_unique(array_filter(
            $package->permissions,
            static fn (mixed $name): bool => is_string($name) && $name !== '',
        )));

        if ($names === [] || ! $this->permissionTablesExist()) {
            return;
        }

        $registrar = resolve(PermissionRegistrar::class);
        $configured = config('capell.roles.super_admin', config('filament-shield.super_admin.name', 'super_admin'));
        $superAdminRoleName = is_string($configured) && $configured !== '' ? $configured : 'super_admin';

        PermissionTeamContext::run(null, function () use ($registrar, $names, $superAdminRoleName): void {
            $role = $registrar->getRoleClass()::findOrCreate($superAdminRoleName, 'web');

            foreach ($names as $name) {
                $role->givePermissionTo($registrar->getPermissionClass()::findOrCreate($name, 'web'));
            }
        });

        $registrar->forgetCachedPermissions();
    }

    private function permissionTablesExist(): bool
    {
        return array_all(['permissions', 'roles', 'role_has_permissions'], fn (string $table) => Schema::hasTable((string) config('permission.table_names.' . $table, $table)));
    }
}
