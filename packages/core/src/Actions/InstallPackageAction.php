<?php

declare(strict_types=1);

namespace Capell\Core\Actions;

use Capell\Core\Actions\Extensions\EnforceExtensionVerificationAction;
use Capell\Core\Actions\Install\RegisterProviderCommandsInCurrentProcessAction;
use Capell\Core\Actions\Install\RunDeclaredPackageMigrationsAction;
use Capell\Core\Actions\RuntimeRefresh\RefreshInstalledPackageRuntimeAction;
use Capell\Core\Actions\RuntimeRefresh\RestartQueueWorkersAction;
use Capell\Core\Contracts\ProgressReporter;
use Capell\Core\Data\PackageData;
use Capell\Core\Enums\ListenerEnum;
use Capell\Core\Events\PackageInstalled;
use Capell\Core\Exceptions\ExtensionVerificationFailedException;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Support\Components\ComponentRegistry;
use Capell\Core\Support\Install\NullProgressReporter;
use Capell\Core\Support\Packages\InstalledRuntimeLifecycle;
use Capell\Core\Support\Packages\PackageLifecycleRunner;
use Capell\Core\Support\Packages\PackageSurfaceRegistrar;
use Exception;
use Illuminate\Support\Facades\Event;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
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
        resolve(InstalledRuntimeLifecycle::class)->assertCanActivate($package->name);
        self::install($package, $arguments, $reporter, $allowLegacyCommand, $freshLifecycleProcess);
        RestartQueueWorkersAction::run();
        if (config('capell.multi_node', false) === true || is_string(config('octane.server'))) {
            $reporter?->report(__('capell-core::runtime-refresh.retained_reload_required'));
        }
    }

    /** @param array<string, mixed> $arguments */
    private static function install(
        PackageData $package,
        array $arguments,
        ?ProgressReporter $reporter,
        bool $allowLegacyCommand,
        bool $freshLifecycleProcess,
        bool $alreadyVerified = false,
    ): void {
        $name = $package->name;
        $reporter ??= new NullProgressReporter;

        $isBundle = $package->getKind() === 'bundle';
        if ($isBundle) {
            // A bundle verifies itself and every member it will install before
            // the first member runs any lifecycle work; members installed from
            // that loop therefore skip their own pass.
            self::verifyBeforeInstall($package, $reporter, $alreadyVerified, array_values(array_filter(
                $package->getRequirements(),
                static fn (string $memberName): bool => ! CapellCore::getPackage($memberName)->isInstalled(),
            )));
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

        if (! $isBundle) {
            self::verifyBeforeInstall($package, $reporter, $alreadyVerified, []);
        }

        CapellCore::markPackageInstalling($package->name);

        try {
            // Installing flips the package to installed and re-boots its
            // provider, so its boot-only surfaces (blueprint subjects,
            // outbound events) arrive after the container froze those
            // registries on `booted`. The window below is what makes that
            // legal — without it a fresh-database install either throws
            // "cannot be registered after boot" or silently ends up with no
            // package surfaces at all.
            resolve(PackageSurfaceRegistrar::class)->duringPackageInstallation(
                function () use ($package, $arguments, $reporter, $allowLegacyCommand, $freshLifecycleProcess): void {
                    RegisterProviderCommandsInCurrentProcessAction::run(function () use ($package): void {
                        if ($package->serviceProviderClass !== null) {
                            app()->getProvider($package->serviceProviderClass)?->callBootedCallbacks();
                        }

                        foreach (array_unique(array_merge($package->getProviderClasses('install'), $package->getProviderClasses('console'))) as $providerClass) {
                            app()->register($providerClass);
                        }
                    });

                    RunDeclaredPackageMigrationsAction::run($package, $reporter);

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
                },
            );
        } catch (Throwable $throwable) {
            CapellCore::markPackageFailed($package->name, $throwable->getMessage());

            throw $throwable;
        }

        resolve(ComponentRegistry::class)->clearCachedComponentsOrFail();
        CapellCore::subscriberManager()->notifySubscribers(ListenerEnum::PackageInstalled, $package);
        Event::dispatch(new PackageInstalled($package));
    }

    /**
     * @param  list<string>  $memberNames  bundle members that are not installed yet
     */
    private static function verifyBeforeInstall(PackageData $package, ProgressReporter $reporter, bool $alreadyVerified, array $memberNames): void
    {
        try {
            if (! $alreadyVerified) {
                EnforceExtensionVerificationAction::run($package->name, $reporter);
            }

            foreach ($memberNames as $memberName) {
                EnforceExtensionVerificationAction::run($memberName, $reporter);
            }
        } catch (ExtensionVerificationFailedException $extensionVerificationFailedException) {
            CapellCore::markPackageFailed($package->name, $extensionVerificationFailedException->getMessage());

            throw $extensionVerificationFailedException;
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

                self::install($member, $arguments, $reporter, $allowLegacyCommand, $freshLifecycleProcess, alreadyVerified: true);
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
        RefreshInstalledPackageRuntimeAction::run($package);
    }
}
