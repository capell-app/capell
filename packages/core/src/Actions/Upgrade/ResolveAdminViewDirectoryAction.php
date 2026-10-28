<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Upgrade;

use Composer\InstalledVersions;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use Throwable;

class ResolveAdminViewDirectoryAction
{
    use AsFake;
    use AsObject;

    /** Returns the installed Admin package's view directory, or null when it cannot be resolved. */
    public function handle(): ?string
    {
        try {
            $admin = InstalledVersions::getInstallPath('capell-app/admin');
        } catch (Throwable) {
            return null;
        }

        return is_string($admin) && is_dir($admin . '/resources/views') ? $admin . '/resources/views' : null;
    }
}
