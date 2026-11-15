<?php

declare(strict_types=1);

namespace Capell\Core\Listeners;

use Capell\Core\Actions\BuildPackageCacheAction;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Contracts\Foundation\Application;
use Throwable;

/**
 * Composer runs `artisan package:discover` after every install, update and require. Rebuilding the Capell
 * manifest cache there keeps it in step with the installed packages, so a newly required package is
 * registered without a separate `capell:package-cache` step.
 */
final readonly class RebuildPackageCacheAfterPackageDiscovery
{
    public function __construct(private Application $app) {}

    public function handle(CommandFinished $event): void
    {
        if ($event->command !== 'package:discover' || $event->exitCode !== 0) {
            return;
        }

        // An application without a cache discovers on demand; there is nothing stale to replace.
        if (! file_exists($this->app->bootstrapPath('cache/capell-package-manifests.php'))) {
            return;
        }

        // Composer runs this from its post-autoload-dump script; a broken package manifest must be
        // reported, not fail the whole `composer install`.
        try {
            BuildPackageCacheAction::run();
        } catch (Throwable $throwable) {
            report($throwable);
        }
    }
}
