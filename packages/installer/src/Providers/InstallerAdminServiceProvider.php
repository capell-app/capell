<?php

declare(strict_types=1);

namespace Capell\Installer\Providers;

use Capell\Admin\Facades\CapellAdmin;
use Capell\Installer\Bridges\InstallerAdminBridge;
use Filament\Pages\Page;
use Illuminate\Support\ServiceProvider;
use Override;

final class InstallerAdminServiceProvider extends ServiceProvider
{
    #[Override]
    public function register(): void
    {
        $this->app->booted(function (): void {
            if (! class_exists(CapellAdmin::class)
                || ! class_exists(Page::class)) {
                return;
            }

            CapellAdmin::registerAdminBridge(
                InstallerServiceProvider::$packageName,
                InstallerAdminBridge::class,
            );
            CapellAdmin::bootAdminBridges(InstallerServiceProvider::$packageName);
        });
    }
}
