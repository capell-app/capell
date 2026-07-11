<?php

declare(strict_types=1);

namespace Capell\Admin\Actions;

use Capell\Admin\Enums\FilamentWidgetEnum;
use Capell\Admin\Enums\PageEnum;
use Capell\Admin\Enums\PermissionSyncMode;
use Capell\Admin\Enums\ResourceEnum;
use Capell\Admin\Facades\CapellAdmin;
use Filament\Facades\Filament;
use Lorisleiva\Actions\Concerns\AsObject;
use Spatie\Permission\PermissionRegistrar;

class SyncCapellPermissionsAction
{
    use AsObject;

    public function handle(PermissionSyncMode $mode = PermissionSyncMode::Install): void
    {
        AssignPermissionsToRole::run(
            resources: array_merge(
                ResourceEnum::cases(),
                CapellAdmin::getAdminSurfaceRegistry()->resources(),
                Filament::getResources(),
            ),
            pages: array_merge(PageEnum::cases(), CapellAdmin::getAdminSurfaceRegistry()->pages()),
            widgets: array_merge(FilamentWidgetEnum::cases(), Filament::getWidgets()),
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
        }

        resolve(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
