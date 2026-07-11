<?php

declare(strict_types=1);

namespace Capell\Admin\Actions;

use Capell\Admin\Enums\CapellPermission;
use Lorisleiva\Actions\Concerns\AsObject;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

class EnsureCapellPermissionsAction
{
    use AsObject;

    public function handle(?string $guardName = null): void
    {
        $guard = $guardName ?? config('auth.defaults.guard', 'web');

        foreach (CapellPermission::cases() as $permission) {
            Permission::query()->firstOrCreate([
                'name' => $permission->name(),
                'guard_name' => $guard,
            ]);
        }

        resolve(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
