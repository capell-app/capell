<?php

declare(strict_types=1);

namespace Capell\Admin\Actions;

use Capell\Admin\Actions\Users\SeedRolePresetRolesAction;
use Capell\Admin\Enums\FilamentWidgetEnum;
use Capell\Admin\Enums\PageEnum;
use Capell\Admin\Enums\PermissionSyncMode;
use Capell\Admin\Enums\ResourceEnum;
use Capell\Admin\Facades\CapellAdmin;
use Capell\Core\Actions\Permissions\ResolveDeclaredPermissionsAction;
use Capell\Core\Data\PackageData;
use Capell\Core\Data\Permissions\PermissionSetData;
use Capell\Core\Facades\CapellCore;
use Filament\Facades\Filament;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use Spatie\Permission\PermissionRegistrar;

class SyncCapellPermissionsAction
{
    use AsFake;
    use AsObject;

    public function handle(PermissionSyncMode $mode = PermissionSyncMode::Install): void
    {
        $declared = ResolveDeclaredPermissionsAction::run();
        $declaredKeys = array_values(array_unique(array_merge(...array_map(
            static fn (PermissionSetData $set): array => $set->keys(),
            array_values($declared),
        ))));

        AssignPermissionsToRole::run(
            resources: array_merge(
                ResourceEnum::cases(),
                CapellAdmin::getAdminSurfaceRegistry()->resources(),
                Filament::getResources(),
            ),
            pages: array_merge(PageEnum::cases(), CapellAdmin::getAdminSurfaceRegistry()->pages()),
            widgets: array_merge(FilamentWidgetEnum::cases(), Filament::getWidgets()),
            permissions: array_values(CapellCore::getPackages(withoutCore: false)
                ->filter(fn (PackageData $package): bool => CapellCore::isPackageEnabled($package->name))
                ->flatMap(fn (PackageData $package): array => $package->manifest->permissions ?? $package->permissions)
                ->merge($declaredKeys)
                ->unique()
                ->values()
                ->all()),
        );

        AssignPermissionsToRole::run(
            pages: Filament::getPages(),
        );

        EnsureCapellPermissionsAction::run();

        if ($mode === PermissionSyncMode::Install) {
            SeedDefaultRolesAction::run();
        }

        if ($mode === PermissionSyncMode::Upgrade) {
            GrantCapellDefaultRolePermissionsAction::run(PermissionSyncMode::Upgrade);
            SeedRolePresetRolesAction::run(syncExisting: false);
        }

        GrantDeclaredPermissionRolesAction::run($declared);

        resolve(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
