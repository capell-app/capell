<?php

declare(strict_types=1);

namespace Capell\Core\Actions;

use Capell\Core\Actions\Install\PublishPackageMigrationsAction;
use Capell\Core\Actions\Install\RunMigrationsAction;
use Capell\Core\Contracts\ProgressReporter;
use Capell\Core\Data\PackageData;
use Capell\Core\Enums\ListenerEnum;
use Capell\Core\Events\PackageInstalled;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Support\Install\NullProgressReporter;
use Capell\Core\Support\Packages\PackageLifecycleRunner;
use Exception;
use Illuminate\Support\Facades\Event;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Throwable;

/**
 * @method static void run(PackageData $package, array<string, mixed> $arguments = [], ?ProgressReporter $reporter = null, bool $allowLegacyCommand = true, bool $freshLifecycleProcess = false)
 */
class InstallPackageAction
{
    use AsFake;
    use AsObject;

    /**
     * @param  array<string, mixed>  $arguments
     */
    public static function handle(
        PackageData $package,
        array $arguments = [],
        ?ProgressReporter $reporter = null,
        bool $allowLegacyCommand = true,
        bool $freshLifecycleProcess = false,
    ): void {
        $name = $package->name;
        $reporter ??= new NullProgressReporter;

        if ($package->getKind() === 'bundle') {
            self::installBundleMembers($package, $arguments, $reporter, $allowLegacyCommand, $freshLifecycleProcess);
        }

        if (! CapellCore::canInstallPackage($name)) {
            $missing = CapellCore::getMissingRequirements($name);
            if ($missing !== []) {
                throw new Exception(
                    sprintf("Plugin '%s' cannot be installed. Missing required plugin(s): ", $name) . implode(', ', $missing) . '.',
                );
            }
        }

        CapellCore::markPackageInstalling($package->name);

        try {
            if ($package->serviceProviderClass !== null) {
                app()->getProvider($package->serviceProviderClass)?->callBootedCallbacks();
            }

            foreach (array_unique(array_merge($package->getProviderClasses('install'), $package->getProviderClasses('console'))) as $providerClass) {
                app()->register($providerClass);
            }

            self::runDeclaredMigrations($package, $reporter);

            if ($package->getInstallCommand() !== null || $package->getInstallAction() !== null) {
                resolve(PackageLifecycleRunner::class)->run(
                    package: $package,
                    phase: 'install',
                    command: $package->getInstallCommand(),
                    actionClass: $package->getInstallAction(),
                    arguments: $arguments,
                    reporter: $reporter,
                    allowLegacyCommand: $allowLegacyCommand,
                    freshProcess: $freshLifecycleProcess,
                );
            }

            CapellCore::markPackageInstalled($package->name);
            self::registerInstalledPackageProviders($package);
        } catch (Throwable $throwable) {
            CapellCore::markPackageFailed($package->name, $throwable->getMessage());

            throw $throwable;
        }

        CapellCore::clearCachedComponents();
        CapellCore::subscriberManager()->notifySubscribers(ListenerEnum::PackageInstalled, $package);
        Event::dispatch(new PackageInstalled($package));
    }

    private static function runDeclaredMigrations(PackageData $package, ProgressReporter $reporter): void
    {
        $publishSchema = $package->declaresSchemaMigrations();
        $publishSettings = $package->declaresSettingsMigrations();

        if (! $publishSchema && ! $publishSettings) {
            return;
        }

        PublishPackageMigrationsAction::run(
            packages: collect([$package->name => $package]),
            reporter: $reporter,
            publishSchema: $publishSchema,
            publishSettings: $publishSettings,
            requireMigrationFiles: true,
        );

        if ($publishSchema) {
            RunMigrationsAction::run(
                reporter: $reporter,
                includeSettings: false,
            );
        }

        if ($publishSettings) {
            RunMigrationsAction::run(
                reporter: $reporter,
                includeSettings: true,
                includeSchema: false,
            );
        }
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private static function installBundleMembers(
        PackageData $bundle,
        array $arguments,
        ?ProgressReporter $reporter,
        bool $allowLegacyCommand,
        bool $freshLifecycleProcess,
    ): void {
        $newlyInstalled = [];

        try {
            foreach ($bundle->getRequirements() as $memberName) {
                $member = CapellCore::getPackage($memberName);
                if ($member->isInstalled()) {
                    continue;
                }

                self::handle($member, $arguments, $reporter, $allowLegacyCommand, $freshLifecycleProcess);
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
