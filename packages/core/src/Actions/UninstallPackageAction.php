<?php

declare(strict_types=1);

namespace Capell\Core\Actions;

use Capell\Core\Data\PackageData;
use Capell\Core\Enums\ListenerEnum;
use Capell\Core\Events\PackageUninstalled;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Site;
use Capell\Core\Support\Packages\PackageLifecycleRunner;
use Capell\Core\ThemeStudio\Settings\ThemeStudioSettings;
use Exception;
use Illuminate\Database\Eloquent\Builder;
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

        self::guardActiveTheme($package);

        resolve(PackageLifecycleRunner::class)->run(
            package: $package,
            phase: 'uninstall',
            command: null,
            actionClass: $package->getUninstallAction(),
            arguments: [],
            allowLegacyCommand: false,
        );

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

    private static function guardActiveTheme(PackageData $package): void
    {
        $themeKey = $package->getThemeKey();

        if ($themeKey === null) {
            return;
        }

        $activeGlobally = app()->bound(ThemeStudioSettings::class)
            && resolve(ThemeStudioSettings::class)->activeTheme === $themeKey;
        $siteCount = Site::query()->whereHas(
            'theme',
            fn (Builder $themeQuery): Builder => $themeQuery->where('key', $themeKey),
        )->count();
        $layoutCount = Layout::query()->whereHas(
            'theme',
            fn (Builder $themeQuery): Builder => $themeQuery->where('key', $themeKey),
        )->count();

        if (! $activeGlobally && $siteCount === 0 && $layoutCount === 0) {
            return;
        }

        throw new Exception(sprintf(
            "Theme package '%s' cannot be uninstalled while theme '%s' is in use (%d site(s), %d layout(s), global active theme: %s). Assign another installed theme to every site and layout and switch the global active theme first.",
            $package->name,
            $themeKey,
            $siteCount,
            $layoutCount,
            $activeGlobally ? 'yes' : 'no',
        ));
    }

    private static function finalizeUninstall(PackageData $package): void
    {
        CapellCore::markPackageUninstalled($package->name);
        CapellCore::clearCachedComponents();
        CapellCore::subscriberManager()->notifySubscribers(ListenerEnum::PackageUninstalled, $package);
        Event::dispatch(new PackageUninstalled($package));
    }
}
