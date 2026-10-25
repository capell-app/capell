<?php

declare(strict_types=1);

namespace Capell\Installer\Actions;

use Capell\Core\Contracts\AdminPanelUrlResolver;
use Capell\Core\Facades\CapellCore;
use Capell\Installer\Data\InstallerCompletionData;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use Throwable;

final class BuildInstallerCompletionDataAction
{
    use AsFake;
    use AsObject;

    public function handle(): InstallerCompletionData
    {
        $adminUrl = null;

        try {
            if (CapellCore::isPackageInstalled('capell-app/admin')) {
                $adminUrl = resolve(AdminPanelUrlResolver::class)->resolve();
            }
        } catch (Throwable) {
            // A headless or partial install still has a safe site handoff.
        }

        return new InstallerCompletionData(
            adminUrl: $adminUrl,
            siteUrl: rtrim((string) config('app.url'), '/'),
        );
    }
}
