<?php

declare(strict_types=1);

namespace Capell\Core\Support\Install\Cli;

use Capell\Core\Data\Install\DeveloperToolingChoiceData;
use Capell\Core\Support\Install\WelcomeRouteInstaller;
use Closure;

use function Laravel\Prompts\confirm;

use Throwable;

final class InstallPostInstallOptionResolver
{
    /**
     * @param  Closure(string): void  $recordManualInstallChange
     * @param  Closure(string): void  $writeWarning
     */
    public function resolveWelcomeRoute(
        bool $hasFrontend,
        bool $installWelcomeRouteOption,
        bool $interactive,
        WelcomeRouteInstaller $welcomeRouteInstaller,
        Closure $recordManualInstallChange,
        Closure $writeWarning,
    ): bool {
        if (! $hasFrontend) {
            return false;
        }

        if ($installWelcomeRouteOption) {
            return true;
        }

        if (! $welcomeRouteInstaller->canInstall() || ! $interactive) {
            return false;
        }

        $removeExistingHomeRoute = confirm(
            label: 'Remove existing home route?',
            default: true,
            hint: "Removes Laravel's default welcome route so Capell CMS can handle the homepage.",
        );

        $this->configureWelcomeRoute(
            $welcomeRouteInstaller,
            $removeExistingHomeRoute,
            $recordManualInstallChange,
            $writeWarning,
        );

        return $removeExistingHomeRoute;
    }

    public function resolveDeveloperToolingChoice(
        bool $developerToolingRequested,
        bool $skipBoostInstall,
        bool $developerToolingInstalled,
        bool $interactive,
        bool $useFreshDemoDefaults,
    ): DeveloperToolingChoiceData {
        if ($developerToolingRequested) {
            return InstallDeveloperToolingChoices::explicitlyRequested($skipBoostInstall);
        }

        if ($developerToolingInstalled) {
            return InstallDeveloperToolingChoices::alreadyInstalled();
        }

        if (! $interactive || $useFreshDemoDefaults) {
            return InstallDeveloperToolingChoices::notInstalled();
        }

        $installationPrompt = InstallDeveloperToolingChoices::installationPrompt();

        if (! confirm(
            label: $installationPrompt['label'],
            default: $installationPrompt['default'],
            hint: $installationPrompt['hint'],
        )) {
            return InstallDeveloperToolingChoices::notInstalled();
        }

        $boostInstallationPrompt = InstallDeveloperToolingChoices::boostInstallationPrompt();

        return new DeveloperToolingChoiceData(
            installDeveloperTooling: true,
            configureBoostDeveloperTooling: confirm(
                label: $boostInstallationPrompt['label'],
                default: $boostInstallationPrompt['default'],
                hint: $boostInstallationPrompt['hint'],
            ),
        );
    }

    public function resolveDeveloperToolingChoiceForPlan(
        bool $developerToolingRequested,
        bool $skipBoostInstall,
        bool $developerToolingInstalled,
    ): DeveloperToolingChoiceData {
        if ($developerToolingRequested) {
            return InstallDeveloperToolingChoices::explicitlyRequested($skipBoostInstall);
        }

        return $developerToolingInstalled
            ? InstallDeveloperToolingChoices::alreadyInstalled()
            : InstallDeveloperToolingChoices::notInstalled();
    }

    public function shouldRunNpmBuild(bool $hasFrontend, bool $interactive, bool $useFreshDemoDefaults): bool
    {
        if (! $hasFrontend || ! $interactive || $useFreshDemoDefaults) {
            return false;
        }

        return confirm('Would you like to run an npm build after this command completes?', default: true);
    }

    public function shouldRemoveInstallerPackage(
        bool $installerPackageInstalled,
        bool $removeInstallerOption,
        bool $interactive,
        bool $useFreshDemoDefaults,
    ): bool {
        if (! $installerPackageInstalled) {
            return false;
        }

        if ($removeInstallerOption) {
            return true;
        }

        if (! $interactive || $useFreshDemoDefaults) {
            return false;
        }

        return confirm(
            label: 'Delete the installer after installing?',
            hint: 'You can download again by composer require `capell-app/installer`',
        );
    }

    /**
     * @param  Closure(string): void  $recordManualInstallChange
     * @param  Closure(string): void  $writeWarning
     */
    private function configureWelcomeRoute(
        WelcomeRouteInstaller $welcomeRouteInstaller,
        bool $removeExistingHomeRoute,
        Closure $recordManualInstallChange,
        Closure $writeWarning,
    ): void {
        try {
            if ($removeExistingHomeRoute) {
                $welcomeRouteInstaller->enableFrontendHomeRoute();

                return;
            }

            $welcomeRouteInstaller->disableFrontendHomeRoute();
        } catch (Throwable $throwable) {
            $manualChange = $removeExistingHomeRoute
                ? 'Set CAPELL_FRONTEND_REGISTER_HOME_ROUTE=true in .env.'
                : 'Set CAPELL_FRONTEND_REGISTER_HOME_ROUTE=false in .env.';

            $recordManualInstallChange($manualChange . ' ' . $throwable->getMessage());
            $writeWarning('Unable to update .env automatically. Manual changes may be required.');
        }
    }
}
