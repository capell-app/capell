<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Install;

use Capell\Core\Contracts\ProgressReporter;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Lorisleiva\Actions\Concerns\AsObject;
use Spatie\Permission\Models\Role;
use Throwable;

final class GrantInstallUserAdminAccessAction
{
    use AsObject;

    public function handle(Authenticatable $user, ProgressReporter $reporter): void
    {
        if (! Schema::hasTable('roles') || ! Schema::hasTable('model_has_roles')) {
            return;
        }

        $roleName = config('capell.roles.super_admin', 'super_admin');
        $superAdminRoleName = is_string($roleName) && $roleName !== '' ? $roleName : 'super_admin';

        try {
            $role = Role::findOrCreate($superAdminRoleName, 'web');

            if (method_exists($user, 'hasRole') && method_exists($user, 'assignRole')) {
                if ($user->hasRole($role)) {
                    return;
                }

                $user->assignRole($role);
                $reporter->report('✓ Granted admin access to install user.');

                return;
            }

            if (! $role instanceof Role) {
                return;
            }

            $this->assignRoleDirectly($user, $role);
            $reporter->report('✓ Granted admin access to install user.');
        } catch (Throwable) {
            $reporter->report('→ Admin role could not be assigned automatically; assign panel access manually.');
        }
    }

    private function assignRoleDirectly(Authenticatable $user, Role $role): void
    {
        // Use the morph alias (e.g. "User"), not the FQCN. Spatie matches role
        // assignments against $user->getMorphClass() at runtime, so storing the
        // class name here produces rows that never match and silently denies admin
        // access on installs that register a User morph alias. Fall back to the
        // class name if no alias is mapped (getMorphClass() throws when the morph
        // map is required and the model is unmapped).
        $modelType = $this->resolveModelType($user);

        $attributes = [
            'role_id' => $role->getKey(),
            'model_type' => $modelType,
            'model_id' => $user->getAuthIdentifier(),
        ];

        $query = DB::table('model_has_roles')
            ->where('role_id', $attributes['role_id'])
            ->where('model_type', $attributes['model_type'])
            ->where('model_id', $attributes['model_id']);

        if (Schema::hasColumn('model_has_roles', 'team_id')) {
            $attributes['team_id'] = null;
            $query->whereNull('team_id');
        }

        if ($query->exists()) {
            return;
        }

        DB::table('model_has_roles')->insert($attributes);
    }

    private function resolveModelType(Authenticatable $user): string
    {
        if (! $user instanceof Model) {
            return $user::class;
        }

        try {
            return $user->getMorphClass();
        } catch (Throwable) {
            return $user::class;
        }
    }
}
