<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Shield;

use Capell\Admin\Data\Shield\RolePermissionChangeSetData;
use Illuminate\Support\Collection;
use Lorisleiva\Actions\Concerns\AsObject;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class BuildRolePermissionChangeSetAction
{
    use AsObject;

    /**
     * @param  array<string, mixed>  $formState
     */
    public function handle(Role $role, array $formState): RolePermissionChangeSetData
    {
        $before = $this->normalize($role->permissions()->pluck('name'));
        $validPermissionNames = Permission::query()
            ->where('guard_name', $role->guard_name)
            ->pluck('name');
        $after = $this->normalize($this->permissionNamesFromFormState($formState, $validPermissionNames));

        return new RolePermissionChangeSetData(
            before: $before,
            after: $after,
            added: array_values(array_diff($after, $before)),
            removed: array_values(array_diff($before, $after)),
            unchanged: array_values(array_intersect($before, $after)),
        );
    }

    /**
     * @param  array<string, mixed>  $formState
     * @param  Collection<int, string>  $validPermissionNames
     * @return list<string>
     */
    private function permissionNamesFromFormState(array $formState, Collection $validPermissionNames): array
    {
        $validPermissionLookup = $validPermissionNames
            ->flip()
            ->all();

        return array_values(collect($formState)
            ->reject(fn (mixed $value, string $key): bool => in_array($key, ['name', 'guard_name', 'select_all'], true))
            ->filter(fn (mixed $value): bool => is_array($value))
            ->flatten()
            ->filter(fn (mixed $value): bool => is_string($value) && array_key_exists($value, $validPermissionLookup))
            ->values()
            ->all());
    }

    /**
     * @param  Collection<int, string>|array<int, string>  $permissionNames
     * @return list<string>
     */
    private function normalize(Collection|array $permissionNames): array
    {
        return array_values(collect($permissionNames)
            ->filter(fn (mixed $permissionName): bool => is_string($permissionName) && $permissionName !== '')
            ->unique()
            ->sort()
            ->values()
            ->all());
    }
}
