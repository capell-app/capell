<?php

declare(strict_types=1);

namespace Capell\Admin\Actions;

use BezhanSalleh\FilamentShield\Facades\FilamentShield;
use BezhanSalleh\FilamentShield\Support\Utils;
use Capell\Admin\Enums\FilamentWidgetEnum;
use Capell\Admin\Enums\PageEnum;
use Capell\Admin\Enums\ResourceEnum;
use Illuminate\Database\Eloquent\Model;
use LogicException;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use Spatie\Permission\PermissionRegistrar;

/**
 * @method static void run(array<int, ResourceEnum|class-string> $resources = [], array<int, PageEnum|class-string> $pages = [], array<int, FilamentWidgetEnum|class-string> $widgets = [])
 */
class AssignPermissionsToRole
{
    use AsFake;
    use AsObject;

    /**
     * @param  array<int, ResourceEnum|class-string>  $resources
     * @param  array<int, PageEnum|class-string>  $pages
     * @param  array<int, FilamentWidgetEnum|class-string>  $widgets
     */
    public function handle(array $resources = [], array $pages = [], array $widgets = []): void
    {
        $permissions = [
            ...$this->resourcePermissions($resources),
            ...$this->pageOrWidgetPermissions($pages),
            ...$this->pageOrWidgetPermissions($widgets),
        ];

        if ($permissions !== []) {
            $this->grantSuperAdminPermissions(array_values(array_unique($permissions)));
        }
    }

    /**
     * Attach the already-created permissions directly. Spatie's public
     * givePermissionTo() resolves and reloads the complete permission cache for
     * every permission, which exhausts the default CLI memory limit on a full
     * Capell installation.
     *
     * @param  list<string>  $permissions
     */
    private function grantSuperAdminPermissions(array $permissions): void
    {
        if (Utils::isSuperAdminDefinedViaGate() || ! Utils::isSuperAdminEnabled()) {
            return;
        }

        $permissionModel = Utils::getPermissionModel();
        $permissionIds = $permissionModel::query()
            ->where('guard_name', Utils::getFilamentAuthGuard())
            ->whereIn('name', $permissions)
            ->pluck($this->modelKeyName($permissionModel))
            ->all();

        if (Utils::isTenancyEnabled() && ($tenantModel = Utils::getTenantModel()) !== null) {
            foreach ($tenantModel::query()->pluck($this->modelKeyName($tenantModel)) as $tenantId) {
                Utils::createRole(tenantId: $tenantId)->permissions()->syncWithoutDetaching($permissionIds);
            }
        } else {
            Utils::createRole()->permissions()->syncWithoutDetaching($permissionIds);
        }

        resolve(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function modelKeyName(string $modelClass): string
    {
        if (! is_subclass_of($modelClass, Model::class)) {
            throw new LogicException(sprintf('%s must be an Eloquent model.', $modelClass));
        }

        return (new $modelClass)->getKeyName();
    }

    /**
     * @param  array<int, FilamentWidgetEnum|PageEnum|class-string>  $cases
     * @return list<string>
     */
    private function pageOrWidgetPermissions(array $cases): array
    {
        return array_values(array_map(
            static function (FilamentWidgetEnum|PageEnum|string $case): string {
                $class = is_string($case) ? $case : $case->value;

                return Utils::createPermission('View:' . class_basename($class));
            },
            $cases,
        ));
    }

    /**
     * @param  array<int, ResourceEnum|class-string>  $resources
     * @return list<string>
     */
    private function resourcePermissions(array $resources): array
    {
        $permissions = [];

        foreach ($resources as $resource) {
            $resourceClass = is_string($resource) ? $resource : $resource->value;

            foreach (FilamentShield::getResourcePermissions($resourceClass) ?? [] as $permission) {
                $permissions[] = Utils::createPermission($permission);
            }
        }

        return $permissions;
    }
}
