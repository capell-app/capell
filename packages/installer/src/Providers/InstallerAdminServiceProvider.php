<?php

declare(strict_types=1);

namespace Capell\Installer\Providers;

use Capell\Admin\Facades\CapellAdmin;
use Capell\Installer\Bridges\InstallerAdminBridge;
use Illuminate\Support\ServiceProvider;

final class InstallerAdminServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->booted(function (): void {
            if (! class_exists(CapellAdmin::class)
                || ! class_exists('Filament\\Pages\\Page')) {
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
