<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Install;

use Capell\Core\Contracts\ProgressReporter;
use Capell\Core\Data\NewUserData;
use Capell\Core\Models\Site;
use Capell\Core\Support\Permissions\PermissionTeamContext;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use RuntimeException;
use Spatie\Permission\Models\Role;

final class CreateAdditionalInstallUsersAction
{
    use AsFake;
    use AsObject;

    /**
     * @param  array<NewUserData>  $users
     */
    public function handle(array $users, ProgressReporter $reporter, ?Site $site = null): void
    {
        if ($users === []) {
            return;
        }

        /** @var class-string<Model&Authenticatable> $userModel */
        $userModel = config('auth.providers.users.model');

        foreach ($users as $userData) {
            $created = new $userModel()->getConnection()->transaction(function () use ($userModel, $userData, $reporter, $site): bool {
                // firstOrCreate also handles a concurrent insert via the unique email key.
                // Starter accounts must never claim an account that already exists.
                $user = $userModel::query()->firstOrCreate(
                    ['email' => $userData->email],
                    ['name' => $userData->name, 'password' => Hash::make($userData->password)],
                );

                if (! $user->wasRecentlyCreated) {
                    return false;
                }

                if (! $user instanceof Authenticatable) {
                    throw new RuntimeException(sprintf('Install user model [%s] must implement Authenticatable.', $userModel));
                }

                MarkInstallUserEmailVerifiedAction::run($user);
                $this->assignRole($user, $userData, $reporter, $site);

                return true;
            });

            if (! $created) {
                $reporter->report(__('capell-core::install.starter_user_exists', ['email' => $userData->email]));

                continue;
            }

            $reporter->report(sprintf(
                '✓ Prepared install user %s with role %s.',
                $userData->email,
                $userData->roleName ?? 'none',
            ));
        }
    }

    private function assignRole(Authenticatable $user, NewUserData $userData, ProgressReporter $reporter, ?Site $site): void
    {
        if ($userData->roleName === null || $userData->roleName === '') {
            return;
        }

        if (! Schema::hasTable((string) config('permission.table_names.roles', 'roles')) || ! Schema::hasTable((string) config('permission.table_names.model_has_roles', 'model_has_roles'))) {
            $reporter->report(sprintf('→ Role %s could not be assigned to %s automatically.', $userData->roleName, $userData->email));

            return;
        }

        if ($this->isGlobalSuperAdminRole($userData->roleName)) {
            PermissionTeamContext::run(null, function () use ($user, $userData): void {
                $user->assignRole(Role::findOrCreate($userData->roleName, 'web'));
            }, $user instanceof Model ? $user : null);

            return;
        }

        if (! $site instanceof Site || ! method_exists($user, 'assignRoleForSite')) {
            $reporter->report(sprintf('→ Role %s could not be assigned to %s automatically because no site-scoped permission support is available.', $userData->roleName, $userData->email));

            return;
        }

        $role = PermissionTeamContext::run($site->getKey(), fn (): \Spatie\Permission\Contracts\Role => Role::findOrCreate($userData->roleName, 'web'));
        $user->assignRoleForSite($site, $role);
    }

    private function isGlobalSuperAdminRole(string $roleName): bool
    {
        $configured = config('capell.roles.super_admin', config('filament-shield.super_admin.name', 'super_admin'));
        $superAdminRole = is_string($configured) && $configured !== '' ? $configured : 'super_admin';

        return $roleName === $superAdminRole;
    }
}
