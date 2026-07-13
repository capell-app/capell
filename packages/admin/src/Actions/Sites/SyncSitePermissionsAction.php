<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Sites;

use Capell\Admin\Data\SitePermissions\SyncSitePermissionsData;
use Capell\Admin\Data\SitePermissions\UserSiteRoleAssignmentData;
use Capell\Core\Models\Site;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsObject;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * @method static void run(User $actor, Site $site, SyncSitePermissionsData $input)
 */
final class SyncSitePermissionsAction
{
    use AsObject;

    public function handle(User $actor, Site $site, SyncSitePermissionsData $input): void
    {
        throw_unless($actor->can('managePermissions', $site), AuthorizationException::class);

        $this->assertAssignmentsExcludeReservedRoles($input);

        $modelHasRolesTable = $this->modelHasRolesTable();
        $teamColumn = $this->teamColumn();
        $usersById = $this->usersById($input);

        DB::transaction(function () use ($input, $modelHasRolesTable, $site, $teamColumn, $usersById): void {
            DB::table($modelHasRolesTable)
                ->where($teamColumn, $site->getKey())
                ->delete();

            foreach ($input->assignments as $assignment) {
                $user = $usersById->get($assignment->userId);

                if (! $user instanceof User) {
                    continue;
                }

                foreach ($assignment->roleIds as $roleId) {
                    DB::table($modelHasRolesTable)->insertOrIgnore([
                        'role_id' => $roleId,
                        'model_type' => $user->getMorphClass(),
                        'model_id' => $assignment->userId,
                        $teamColumn => $site->getKey(),
                    ]);
                }
            }
        });

        resolve(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function assertAssignmentsExcludeReservedRoles(SyncSitePermissionsData $input): void
    {
        $roleIds = collect($input->assignments)
            ->flatMap(fn (UserSiteRoleAssignmentData $assignment): array => $assignment->roleIds)
            ->unique()
            ->values();

        if ($roleIds->isEmpty()) {
            return;
        }

        $reservedRoleSubmitted = Role::query()
            ->whereKey($roleIds->all())
            ->where('name', (string) config('filament-shield.super_admin.name', 'super_admin'))
            ->exists();

        throw_if($reservedRoleSubmitted, AuthorizationException::class);
    }

    private function modelHasRolesTable(): string
    {
        $tableNames = config('permission.table_names', []);

        return is_array($tableNames) && is_string($tableNames['model_has_roles'] ?? null)
            ? $tableNames['model_has_roles']
            : 'model_has_roles';
    }

    private function teamColumn(): string
    {
        $teamColumn = config('permission.column_names.team_foreign_key', 'team_id');

        return is_string($teamColumn) && $teamColumn !== '' ? $teamColumn : 'team_id';
    }

    /**
     * @return Collection<int, User>
     */
    private function usersById(SyncSitePermissionsData $input): Collection
    {
        $userIds = collect($input->assignments)
            ->map(fn (UserSiteRoleAssignmentData $assignment): int => $assignment->userId)
            ->unique()
            ->values()
            ->all();

        if ($userIds === []) {
            return collect();
        }

        $userModel = $this->userModelClass();

        return $userModel::query()
            ->whereKey($userIds)
            ->get()
            ->keyBy(fn (User $user): int => (int) $user->getKey());
    }

    /**
     * @return class-string<User>
     */
    private function userModelClass(): string
    {
        $userModel = config('auth.providers.users.model', User::class);

        return is_string($userModel) && is_a($userModel, User::class, true) ? $userModel : User::class;
    }
}
