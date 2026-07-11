<?php

declare(strict_types=1);

namespace Capell\Installer\Support;

use Capell\Admin\Providers\AdminServiceProvider;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\Site;
use Capell\Installer\Providers\InstallerServiceProvider;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class InstallerInstallationState
{
    public static function capellIsInstalled(): bool
    {
        try {
            return CapellCore::getPackage(AdminServiceProvider::$packageName)->isInstalled()
                && Schema::hasTable((new Site)->getTable())
                && Site::query()->exists();
        } catch (Throwable) {
            return false;
        }
    }

    public static function capellIsNotInstalled(): bool
    {
        return ! self::capellIsInstalled();
    }

    public static function installerPackageIsInstalled(): bool
    {
        return CapellCore::getPackage(InstallerServiceProvider::$packageName)->isInstalled();
    }
}
