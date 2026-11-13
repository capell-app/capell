<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Users;

use Capell\Admin\Actions\Shield\ResolveDefaultRolePermissionsAction;
use Capell\Admin\Enums\RolePreset;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Creates a role for every shipped preset.
 *
 * On install the preset's permissions are synced. On upgrade only a missing
 * role is created, so an owner's own changes to an existing preset survive.
 */
final class SeedRolePresetRolesAction
{
    use AsFake;
    use AsObject;

    public function handle(bool $syncExisting = true): void
    {
        $guardName = (string) config('auth.defaults.guard', 'web');

        foreach (RolePreset::cases() as $preset) {
            $exists = Role::query()
                ->where('name', $preset->roleName())
                ->where('guard_name', $guardName)
                ->exists();

            if ($exists && ! $syncExisting) {
                continue;
            }

            $role = Role::findOrCreate($preset->roleName(), $guardName);

            $role->syncPermissions(Permission::query()
                ->where('guard_name', $guardName)
                ->whereIn('name', ResolveDefaultRolePermissionsAction::run($preset->roleName(), $guardName))
                ->get());
        }
    }
}
