<?php

declare(strict_types=1);

namespace Capell\Installer\Actions;

use Capell\Core\Actions\RemovePackageAction;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Support\Deployment\ReleaseRootWriteGuard;
use Capell\Installer\Providers\InstallerServiceProvider;
use Illuminate\Support\Facades\Artisan;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use Throwable;

final class RemoveSetupPackageAction
{
    use AsFake;
    use AsObject;

    public function handle(bool $requiresServerSideTooling = false): string
    {
        new ReleaseRootWriteGuard()->assertWritable(
            'remove-installer',
            ['composer.json', 'composer.lock', 'vendor', 'bootstrap/cache'],
            requiresServerSideTooling: $requiresServerSideTooling,
        );

        $redirectUrl = $this->redirectUrl();

        $this->clearFilamentComponentCache();

        RemovePackageAction::run(InstallerServiceProvider::$packageName, requiresServerSideTooling: $requiresServerSideTooling);

        return $redirectUrl;
    }

    private function clearFilamentComponentCache(): void
    {
        try {
            Artisan::call('filament:clear-cached-components');
        } catch (Throwable) {
            // Cache cleanup is best-effort because the command may be unavailable after a partial install.
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
