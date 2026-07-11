<?php

declare(strict_types=1);

namespace Capell\Installer\Actions;

use Capell\Core\Actions\RemovePackageAction;
use Capell\Core\Facades\CapellCore;
use Capell\Installer\Providers\InstallerServiceProvider;
use Illuminate\Support\Facades\Artisan;
use Lorisleiva\Actions\Concerns\AsObject;
use Throwable;

final class RemoveSetupPackageAction
{
    use AsObject;

    public function handle(): string
    {
        $redirectUrl = $this->redirectUrl();

        $this->clearFilamentComponentCache();

        RemovePackageAction::run(InstallerServiceProvider::$packageName);

        return $redirectUrl;
    }

    private function clearFilamentComponentCache(): void
    {
        try {
            Artisan::call('filament:clear-cached-components');
        } catch (Throwable) {
            //
        }
    }

    private function redirectUrl(): string
    {
        return $this->adminPackageIsInstalled() ? url('/admin') : url('/');
    }

    private function adminPackageIsInstalled(): bool
    {
        try {
            return CapellCore::isPackageInstalled('capell-app/admin');
        } catch (Throwable) {
            return false;
        }
    }
}
