<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Sites;

use Capell\Admin\Data\SitePermissions\SitePermissionChangeData;
use Capell\Admin\Data\SitePermissions\SitePermissionsReviewData;
use Capell\Admin\Data\SitePermissions\SyncSitePermissionsData;
use Capell\Admin\Data\SitePermissions\UserSiteRoleAssignmentData;
use Capell\Core\Models\Site;
use Capell\Core\Support\Permissions\SiteAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * @method static void run(User $actor, Site $site, SyncSitePermissionsData $input, ?string $confirmationToken = null)
 */
final class SyncSitePermissionsAction
{
    use AsFake;
    use AsObject;

    public function handle(User $actor, Site $site, SyncSitePermissionsData $input, ?string $confirmationToken = null): void
    {
        $changed = DB::transaction(function () use ($actor, $site, $input, $confirmationToken): bool {
            // Serialise this workflow even when the site currently has no assignments.
            $site->newQuery()->whereKey($site->getKey())->lockForUpdate()->firstOrFail();
            try {
                $review = $this->review($actor, $site, $input, lock: true);
            } catch (AuthorizationException $authorizationException) {
                if ($confirmationToken !== null) {
                    $this->stale();
                }

                throw $authorizationException;
            }

            if ($confirmationToken !== null) {
                $confirmed = SitePermissionsReviewData::fromConfirmationToken($confirmationToken);
                if (! hash_equals($review->fingerprint, $confirmed->fingerprint)) {
                    $this->stale();
                }
            } elseif ($review->revocations !== []) {
                throw ValidationException::withMessages([
                    'assignments' => __('capell-admin::site_permissions.review_required'),
                ]);
            }

            return $this->reconcileRows($site, $input);
        });

        if ($changed) {
            resolve(PermissionRegistrar::class)->forgetCachedPermissions();
            $actor->unsetRelation('roles')->unsetRelation('permissions');
        }
    }

    public function review(User $actor, Site $site, SyncSitePermissionsData $input, ?string $baseline = null, bool $lock = false): SitePermissionsReviewData
    {
        $freshActor = $actor->newQuery()->whereKey($actor->getKey())->when($lock, fn ($query) => $query->lockForUpdate())->first();
        if (! $freshActor instanceof User || ! $freshActor->can('managePermissions', $site)) {
            if ($baseline !== null) {
                $this->stale();
            }

            throw new AuthorizationException;
        }

        $rows = $this->currentRows($site, $lock);
        if ($baseline !== null) {
            $this->assertCurrentToken($baseline, $this->fingerprint($rows));
        }

        $userModel = $this->userModelClass();
        $userIds = collect($input->assignments)->map(fn (UserSiteRoleAssignmentData $assignment): int => $assignment->userId)
            ->merge(collect($rows)->pluck('model_id'))->unique()->sort()->values()->all();
        $users = $userModel::query()->whereKey($userIds)->when($lock, fn ($query) => $query->lockForUpdate())->get()
            ->keyBy(fn (User $user): int => (int) $user->getKey());
        $roles = Role::query()->with('permissions')->whereIn('id', collect($rows)->pluck('role_id')
            ->merge(collect($input->assignments)->flatMap(fn (UserSiteRoleAssignmentData $assignment): array => $assignment->roleIds))->unique()->all())
            ->when($lock, fn ($query) => $query->lockForUpdate())->get()->keyBy('id');

        $this->assertAssignmentsExcludeReservedRoles($input, $site);

        $desired = [];
        foreach ($input->assignments as $assignment) {
            if (! $users->has($assignment->userId)) {
                $this->stale();
            }

            foreach ($assignment->roleIds as $roleId) {
                $desired[$assignment->userId . ':' . $roleId] = [$assignment->userId, $roleId];
            }
        }

        ksort($desired);
        $existing = [];
        foreach ($rows as $row) {
            $existing[$row['model_id'] . ':' . $row['role_id']] = [$row['model_id'], $row['role_id']];
        }

        ksort($existing);

        /**
         * @param  array<string, array{int, int}>  $pairs
         * @return list<SitePermissionChangeData>
         */
        $changes = function (array $pairs, bool $revoking) use ($users, $roles, $desired, $freshActor): array {
            $changes = [];
            foreach ($pairs as [$userId, $roleId]) {
                $user = $users->get($userId);
                $role = $roles->get($roleId);
                // Unavailable members can only lose grants. Availability remains
                // part of the fingerprint, so disappearing after review is stale.
                if ((! $user instanceof User && ! $revoking) || ! $role instanceof Role) {
                    $this->stale();
                }

                $retainsRole = collect($desired)->contains(fn (array $pair): bool => $pair[0] === $userId);
                $changes[] = new SitePermissionChangeData(
                    userId: $userId,
                    userName: $user instanceof User
                        ? sprintf('%s <%s>', $user->getAttribute('name'), $user->getAttribute('email'))
                        : __('capell-admin::site_permissions.unavailable_member', ['id' => $userId]),
                    roleId: $roleId,
                    roleName: $role->name,
                    losesSiteAccess: $revoking && $user instanceof User && ! $retainsRole && ! SiteAccess::forActor($user)->isGlobal(),
                    isActor: $userId === (int) $freshActor->getKey(),
                );
            }

            return $changes;
        };

        return new SitePermissionsReviewData(
            actorId: (int) $actor->getKey(),
            siteId: (int) $site->getKey(),
            fingerprint: $this->fingerprint([
                (int) $actor->getKey(), (int) $site->getKey(), $rows, $desired,
                $users->map(fn (User $user): array => [$user->getKey(), $user->getAttribute('name'), $user->getAttribute('email'), SiteAccess::forActor($user)->isGlobal()])->sortKeys()->all(),
                $roles->map(fn (Role $role): array => [$role->name, $role->guard_name, $role->getAttribute($this->teamColumn()), $role->permissions->pluck('name')->sort()->values()->all()])->sortKeys()->all(),
            ]),
            additions: $changes(array_diff_key($desired, $existing), false),
            revocations: $changes(array_diff_key($existing, $desired), true),
        );
    }

    public function baseline(Site $site): string
    {
        return Crypt::encryptString($this->fingerprint($this->currentRows($site)));
    }

    private function reconcileRows(Site $site, SyncSitePermissionsData $input): bool
    {
        $userModel = $this->userModelClass();
        $modelType = (new $userModel)->getMorphClass();
        $desired = [];
        foreach ($input->assignments as $assignment) {
            foreach ($assignment->roleIds as $roleId) {
                $desired[$assignment->userId . ':' . $roleId . ':' . $modelType] = [
                    'model_id' => $assignment->userId, 'role_id' => $roleId, 'model_type' => $modelType,
                ];
            }
        }

        $existing = [];
        foreach ($this->currentRows($site, lock: true) as $row) {
            $existing[$row['model_id'] . ':' . $row['role_id'] . ':' . $row['model_type']] = $row;
        }

        // Diff physical rows: a retained FQCN grant must become readable through
        // the current alias, even when an alias pivot already exists for it.
        $removals = array_diff_key($existing, $desired);
        $additions = array_diff_key($desired, $existing);
        $table = $this->modelHasRolesTable();
        $teamColumn = $this->teamColumn();
        foreach ($removals as $row) {
            DB::table($table)
                ->where($teamColumn, $site->getKey())
                ->where('model_type', $row['model_type'])
                ->where('model_id', $row['model_id'])
                ->where('role_id', $row['role_id'])
                ->delete();
        }

        foreach ($additions as $row) {
            DB::table($table)->insert([...$row, $teamColumn => $site->getKey()]);
        }

        return $removals !== [] || $additions !== [];
    }

    /** @return list<array{model_id: int, role_id: int, model_type: string}> */
    private function currentRows(Site $site, bool $lock = false): array
    {
        $userModel = $this->userModelClass();

        return array_values(DB::table($this->modelHasRolesTable())
            ->where($this->teamColumn(), $site->getKey())
            ->whereIn('model_type', array_values(array_unique([$userModel, (new $userModel)->getMorphClass()])))
            ->orderBy('model_id')->orderBy('role_id')->orderBy('model_type')
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->get(['model_id', 'role_id', 'model_type'])
            ->map(fn (object $row): array => ['model_id' => (int) $row->model_id, 'role_id' => (int) $row->role_id, 'model_type' => (string) $row->model_type])
            ->all());
    }

    /** @param array<mixed> $value */
    private function fingerprint(array $value): string
    {
        return hash('sha256', json_encode($value, JSON_THROW_ON_ERROR));
    }

    private function assertCurrentToken(string $token, string $fingerprint): void
    {
        try {
            if (hash_equals($fingerprint, Crypt::decryptString($token))) {
                return;
            }
        } catch (DecryptException) {
            // Invalid or altered review tokens have the same recovery as stale ones.
        }

        $this->stale();
    }

    private function stale(): never
    {
        throw ValidationException::withMessages([
            'assignments' => __('capell-admin::site_permissions.stale'),
        ]);
    }

    private function assertAssignmentsExcludeReservedRoles(SyncSitePermissionsData $input, Site $site): void
    {
        $roleIds = collect($input->assignments)
            ->flatMap(fn (UserSiteRoleAssignmentData $assignment): array => $assignment->roleIds)
            ->unique()
            ->values();

        if ($roleIds->isEmpty()) {
            return;
        }

        $validRoleCount = Role::query()
            ->whereKey($roleIds->all())
            ->where('guard_name', 'web')
            ->where(fn ($query) => $query->whereNull($this->teamColumn())->orWhere($this->teamColumn(), $site->getKey()))
            ->count();

        throw_unless($validRoleCount === $roleIds->count(), AuthorizationException::class);

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
     * @return class-string<User>
     */
    private function userModelClass(): string
    {
        $userModel = config('auth.providers.users.model', User::class);

        return is_string($userModel) && is_a($userModel, User::class, true) ? $userModel : User::class;
    }
}
