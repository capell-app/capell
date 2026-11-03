<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Install;

use Capell\Admin\Providers\AdminServiceProvider;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\Site;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Whether the admin package is installed far enough to serve panel requests.
 *
 * Middleware that runs before the installer redirect must not touch Capell's
 * tables on an uninstalled site, so every such check shares this one answer.
 */
final class AdminInstallState
{
    public function installed(): bool
    {
        try {
            return CapellCore::getPackage(AdminServiceProvider::$packageName)->isInstalled()
                && Schema::hasTable(new Site()->getTable());
        } catch (Throwable) {
            return false;
        }
    }
}
