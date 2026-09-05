<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Install;

use Capell\Core\Contracts\ProgressReporter;
use Capell\Core\Data\NewUserData;
use Capell\Core\Models\Site;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Throwable;

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
            $user = $userModel::query()->where('email', $userData->email)->first();

            if (! $user instanceof Authenticatable) {
                $user = $userModel::query()->create([
                    'name' => $userData->name,
                    'email' => $userData->email,
                    'password' => Hash::make($userData->password),
                ]);
            } else {
                $user->forceFill([
                    'name' => $userData->name,
                    'password' => Hash::make($userData->password),
                ])->save();
            }

            if (! $user instanceof Authenticatable) {
                throw new RuntimeException(sprintf('Install user model [%s] must implement Authenticatable.', $userModel));
            }

            MarkInstallUserEmailVerifiedAction::run($user);
            $this->assignRole($user, $userData, $reporter, $site);

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

        if (! Schema::hasTable('roles') || ! Schema::hasTable('model_has_roles')) {
            $reporter->report(sprintf('→ Role %s could not be assigned to %s automatically.', $userData->roleName, $userData->email));

            return;
        }

        try {
            $role = Role::findOrCreate($userData->roleName, 'web');

            if ($this->isGlobalSuperAdminRole($userData->roleName)) {
                $user->assignRole($role);

                return;
            }

            if (! $site instanceof Site || ! method_exists($user, 'assignRoleForSite')) {
                $reporter->report(sprintf('→ Role %s could not be assigned to %s automatically because no site-scoped permission support is available.', $userData->roleName, $userData->email));

                return;
            }

            $user->assignRoleForSite($site, $role);
        } catch (Throwable) {
            $reporter->report(sprintf('→ Role %s could not be assigned to %s automatically.', $userData->roleName, $userData->email));
        }
    }

    private function isGlobalSuperAdminRole(string $roleName): bool
    {
        $configured = config('capell.roles.super_admin', config('filament-shield.super_admin.name', 'super_admin'));
        $superAdminRole = is_string($configured) && $configured !== '' ? $configured : 'super_admin';

        return $roleName === $superAdminRole;
    }
}
