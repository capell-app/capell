<?php

declare(strict_types=1);

namespace Capell\Core\Actions;

use Capell\Core\Data\PackageData;
use Capell\Core\Enums\ListenerEnum;
use Capell\Core\Events\PackageUninstalled;
use Capell\Core\Facades\CapellCore;
use Exception;
use Illuminate\Support\Facades\Event;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * @method static void run(PackageData $package, bool $delete = false, bool $deleteData = false)
 */
class UninstallPackageAction
{
    use AsObject;

    public static function handle(PackageData $package, bool $delete = false, bool $deleteData = false): void
    {
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

        if ($delete && $package->getKind() === 'bundle') {
            RemovePackageAction::run($package->name);
            DeleteExtensionDataAction::run($package);
            self::finalizeUninstall($package);

            return;
        }

        DeletePackageMigrationsAction::run($package);

        if ($delete || $deleteData) {
            DeleteExtensionDataAction::run($package);
        }

        self::finalizeUninstall($package);

        if ($delete) {
            RemovePackageAction::run($package->name);
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
