<?php

declare(strict_types=1);

namespace Capell\Core\Actions;

use Capell\Core\Contracts\ProgressReporter;
use Capell\Core\Data\PackageData;
use Capell\Core\Enums\ListenerEnum;
use Capell\Core\Events\PackageInstalled;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Support\Packages\PackageLifecycleRunner;
use Exception;
use Illuminate\Support\Facades\Event;
use Lorisleiva\Actions\Concerns\AsObject;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Throwable;

/**
 * @method static void run(PackageData $package, array<string, mixed> $arguments = [], ?ProgressReporter $reporter = null, bool $allowLegacyCommand = true)
 */
class InstallPackageAction
{
    use AsObject;

    /**
     * @param  array<string, mixed>  $arguments
     */
    public static function handle(
        PackageData $package,
        array $arguments = [],
        ?ProgressReporter $reporter = null,
        bool $allowLegacyCommand = true,
    ): void {
        $name = $package->name;

        if ($package->getKind() === 'bundle') {
            self::installBundleMembers($package, $arguments, $reporter, $allowLegacyCommand);
        }

        if (! CapellCore::canInstallPackage($name)) {
            $missing = CapellCore::getMissingRequirements($name);
            if ($missing !== []) {
                throw new Exception(
                    sprintf("Plugin '%s' cannot be installed. Missing required plugin(s): ", $name) . implode(', ', $missing) . '.',
                );
            }
        }

        if ($package->serviceProviderClass !== null) {
            app()->getProvider($package->serviceProviderClass)?->callBootedCallbacks();
        }

        if ($package->getInstallCommand() !== null || $package->getInstallAction() !== null) {
            CapellCore::markPackageInstalling($package->name);

            try {
                foreach (array_unique(array_merge($package->getProviderClasses('install'), $package->getProviderClasses('console'))) as $providerClass) {
                    app()->register($providerClass);
                }

                resolve(PackageLifecycleRunner::class)->run(
                    package: $package,
                    phase: 'install',
                    command: $package->getInstallCommand(),
                    actionClass: $package->getInstallAction(),
                    arguments: $arguments,
                    reporter: $reporter,
                    allowLegacyCommand: $allowLegacyCommand,
                );
            } catch (Throwable $exception) {
                CapellCore::markPackageFailed($package->name, $exception->getMessage());

                throw $exception;
            }
        }

        CapellCore::markPackageInstalled($package->name);
        self::registerInstalledPackageProviders($package);
        CapellCore::clearCachedComponents();
        CapellCore::subscriberManager()->notifySubscribers(ListenerEnum::PackageInstalled, $package);
        Event::dispatch(new PackageInstalled($package));
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private static function installBundleMembers(
        PackageData $bundle,
        array $arguments,
        ?ProgressReporter $reporter,
        bool $allowLegacyCommand,
    ): void {
        $newlyInstalled = [];

        try {
            foreach ($bundle->getRequirements() as $memberName) {
                $member = CapellCore::getPackage($memberName);
                if ($member->isInstalled()) {
                    continue;
                }

                self::handle($member, $arguments, $reporter, $allowLegacyCommand);
                $newlyInstalled[] = $member;
            }
        } catch (Throwable $throwable) {
            foreach (array_reverse($newlyInstalled) as $member) {
                CapellCore::markPackageUninstalled($member->name);
            }

            throw $throwable;
        }
    }

    private static function registerInstalledPackageProviders(PackageData $package): void
    {
        foreach (['auth', 'runtime', 'admin', 'frontend'] as $context) {
            foreach ($package->getProviderClasses($context) as $providerClass) {
                app()->register($providerClass);
                app()->getProvider($providerClass)?->callBootedCallbacks();
            }
        }

        if ($package->serviceProviderClass === null) {
            return;
        }

        $provider = app()->getProvider($package->serviceProviderClass);

        if ($provider instanceof PackageServiceProvider) {
            $provider->callBootedCallbacks();
        }
    }
}
