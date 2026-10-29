<?php

declare(strict_types=1);

namespace Capell\Installer\Actions;

use Capell\Core\Support\Deployment\ReleaseRootWriteGuard;
use Capell\Core\Support\Install\WelcomeRouteInstaller;
use Illuminate\Support\Facades\Artisan;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use RuntimeException;

final class RemoveWelcomeRouteAfterInstallAction
{
    use AsFake;
    use AsObject;

    public function handle(): bool
    {
        new ReleaseRootWriteGuard()->assertWritable('remove-welcome-route', ['routes/web.php', '.env', 'bootstrap/cache']);

        if (! resolve(WelcomeRouteInstaller::class)->install()) {
            return false;
        }

        foreach (['config:clear', 'route:clear'] as $command) {
            throw_if(Artisan::call($command) !== 0, RuntimeException::class, 'The welcome route was removed, but ' . $command . ' failed. Clear the configuration and route caches manually.');
        }

        return true;
    }
}
