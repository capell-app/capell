<?php

declare(strict_types=1);

namespace Capell\Admin\Actions;

use Lorisleiva\Actions\Concerns\AsObject;
use Spatie\Permission\Models\Permission;

class InstallImpersonationPermissionAction
{
    use AsObject;

    public const string PERMISSION_IMPERSONATE = 'impersonate_users';

    public function handle(?string $guardName = null): void
    {
        $guard = $guardName ?? config('auth.defaults.guard', 'web');

        Permission::query()->firstOrCreate([
            'name' => self::PERMISSION_IMPERSONATE,
            'guard_name' => $guard,
        ]);
    }
}
