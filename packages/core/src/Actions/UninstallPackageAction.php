<?php

declare(strict_types=1);

namespace Capell\Core\Actions;

use Capell\Core\Data\PackageData;
use Capell\Core\Enums\ListenerEnum;
use Capell\Core\Events\PackageUninstalled;
use Capell\Core\Exceptions\PackageMigrationCleanupException;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Support\Packages\ActiveThemeUninstallGuard;
use Capell\Core\Support\Packages\PackageLifecycleRunner;
use Exception;
use Illuminate\Support\Facades\Event;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * @method static void run(PackageData $package, bool $delete = false, bool $deleteData = false, bool $requiresServerSideTooling = false)
 */
class UninstallPackageAction
{
    use AsFake;
    use AsObject;

    /**
     * @param  bool  $requiresServerSideTooling  Whether the Composer removal this
     *                                           uninstall may trigger is an unattended write driven by an HTTP
     *                                           request. A property of the call site rather than of the removal:
     *                                           the admin panel passes true, `capell:extension-uninstall` — which an
     *                                           operator runs directly in a terminal — leaves it false.
     */
    public static function handle(
        PackageData $package,
        bool $delete = false,
        bool $deleteData = false,
        bool $requiresServerSideTooling = false,
    ): void {
        if (! $package->isInstalled()) {
            throw new Exception(sprintf("Plugin '%s' is not installed.", $package->name));
        }

        // Prevent uninstall if other installed packages depend on this one
        if (! CapellCore::canUninstallPackage($package->name)) {
            $dependents = CapellCore::getDependentInstalledPackages($package->name)->pluck('name')->all();
            throw new Exception(
                sprintf("Plugin '%s' cannot be uninstalled because the following installed plugin(s) depend on it: ", $package->name) . implode(', ', $dependents) . '.',
            );
        }

        new ActiveThemeUninstallGuard()->assert($package);

        $retryCommand = 'php artisan capell:extension-uninstall ' . $package->name
            . ($delete ? ' --delete-package' : ($deleteData ? ' --delete-data' : ''));
        // Reject known permission failures without changing files. Hooks still
        // need the published migrations, and a failing hook must leave them intact.
        self::runMigrationCleanup($package, $retryCommand, dryRun: true);

        resolve(PackageLifecycleRunner::class)->run(
            package: $package,
            phase: 'uninstall',
            command: null,
            actionClass: $package->getUninstallAction(),
            arguments: [],
            allowLegacyCommand: false,
        );

        // Check the real result too: filesystem state can change after preflight.
        self::runMigrationCleanup($package, $retryCommand, dryRun: false);

        if ($delete && $package->getKind() === 'bundle') {
            RemovePackageAction::run($package->name, requiresServerSideTooling: $requiresServerSideTooling);
            DeleteExtensionDataAction::run($package);
            self::finalizeUninstall($package);

            return;
        }

        if ($delete || $deleteData) {
            DeleteExtensionDataAction::run($package);
        }

        self::finalizeUninstall($package);

        if ($delete) {
            RemovePackageAction::run($package->name, requiresServerSideTooling: $requiresServerSideTooling);
        }
    }

    private static function runMigrationCleanup(PackageData $package, string $retryCommand, bool $dryRun): void
    {
        $cleanup = DeletePackageMigrationsAction::run($package, dryRun: $dryRun);

        if ($cleanup['blocked'] > 0) {
            throw new PackageMigrationCleanupException($cleanup['blockedPaths'], $retryCommand);
        }
    }

    private static function finalizeUninstall(PackageData $package): void
    {
        CapellCore::markPackageUninstalled($package->name);
        CapellCore::clearCachedComponents();
        CapellCore::subscriberManager()->notifySubscribers(ListenerEnum::PackageUninstalled, $package);
        Event::dispatch(new PackageUninstalled($package));
    }
}
