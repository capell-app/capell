<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Install;

use Capell\Admin\Actions\SyncCapellPermissionsAction;
use Capell\Admin\Enums\PermissionSyncMode;
use Capell\Admin\Support\InstalledPanelRuntime;
use Capell\Core\Contracts\AdminPermissionSynchronizer as AdminPermissionSynchronizerContract;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Support\PackageRegistry\CapellPackageLoader;
use Filament\Facades\Filament;
use Throwable;

final class AdminPermissionSynchronizer implements AdminPermissionSynchronizerContract
{
    public function __construct(private readonly CapellPackageLoader $packageLoader) {}

    public function hasBootedPanel(): bool
    {
        try {
            $panel = Filament::getDefaultPanel();
        } catch (Throwable) {
            return false;
        }

        // A panel withheld for late topology lacks the resources permissions must cover.
        return ! resolve(InstalledPanelRuntime::class)->isUnavailable($panel->getId());
    }

    public function syncForInstall(): void
    {
        CapellCore::clearExtensionCache();
        $this->packageLoader->loadProviders();

        SyncCapellPermissionsAction::run(PermissionSyncMode::Install);
    }
}
