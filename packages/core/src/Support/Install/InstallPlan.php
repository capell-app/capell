<?php

declare(strict_types=1);

namespace Capell\Core\Support\Install;

use Capell\Core\Data\Install\InstallStepData;
use Capell\Core\Data\InstallInputData;
use Capell\Core\Data\PackageData;
use Capell\Core\Facades\CapellCore;
use Illuminate\Support\Collection;

final class InstallPlan
{
    public const string STEP_PREFLIGHT_CHECKS = 'preflight-checks';

    public const string STEP_PREPARE_FRESH_INSTALL = 'prepare-fresh-install';

    public const string STEP_PREPARE_ENVIRONMENT = 'prepare-environment';

    public const string STEP_PUBLISH_VENDOR_MIGRATIONS = 'publish-vendor-migrations';

    public const string STEP_RUN_MIGRATIONS_PRE = 'run-migrations-pre';

    public const string STEP_RESOLVE_USER = 'resolve-user';

    public const string STEP_PUBLISH_CAPELL_MIGRATIONS = 'publish-capell-migrations';

    public const string STEP_PUBLISH_PACKAGE_MIGRATIONS = 'publish-package-migrations';

    public const string STEP_PUBLISH_CAPELL_SETTINGS_MIGRATIONS = 'publish-capell-settings-migrations';

    public const string STEP_RUN_MIGRATIONS_MID = 'run-migrations-mid';

    public const string STEP_INSTALL_FILAMENT_PANEL = 'install-filament-panel';

    public const string STEP_REQUIRE_EXTRA_PACKAGES = 'require-extra-packages';

    public const string STEP_INSTALL_DEVELOPER_TOOLING = 'install-developer-tooling';

    public const string STEP_PUBLISH_EXTRA_VENDOR_MIGRATIONS = 'publish-extra-vendor-migrations';

    public const string STEP_INSTALL_PACKAGES = 'install-packages';

    public const string STEP_INSTALL_PACKAGE_PREFIX = 'install-package:';

    public const string STEP_SETUP_PACKAGE_PREFIX = 'setup-package:';

    public const string STEP_DEMO_PACKAGE_PREFIX = 'demo-package:';

    public const string STEP_AFTER_INSTALL_PACKAGE_PREFIX = 'after-install-package:';

    public const string STEP_INTEGRATE_ADMIN_PANEL = 'integrate-admin-panel';

    public const string STEP_RUN_MIGRATIONS_POST = 'run-migrations-post';

    public const string STEP_CLEAR_CACHES = 'clear-caches';

    public const string STEP_REBUILD_RESOURCES = 'rebuild-resources';

    public const string STEP_INSTALL_WELCOME_ROUTE = 'install-welcome-route';

    public const string STEP_GENERATE_SITEMAP = 'generate-sitemap';

    public const string STEP_SEED_DATABASE = 'seed-database';

    public const string STEP_RUN_DOCTOR_SUMMARY = 'run-doctor-summary';

    public const string STEP_MARK_CORE_INSTALLED = 'mark-core-installed';

    /**
     * Build an ordered list of step descriptors for the given input.
     *
     * @return array<int, array{key: string, label: string}>
     */
    public static function build(InstallInputData $inputData): array
    {
        return self::steps($inputData)
            ->map(fn (InstallStepData $step): array => $step->toPlanArray())
            ->all();
    }

    /**
     * Build an ordered collection of typed step descriptors for the given input.
     *
     * @return Collection<int, InstallStepData>
     */
    public static function steps(InstallInputData $inputData): Collection
    {
        $shouldInstallFilamentPanel = self::shouldInstallFilamentPanel($inputData->packages);
        $shouldInstallFilamentPanelAfterRequiringPackages = self::shouldInstallFilamentPanel($inputData->extraPackages);
        $hasSelectedPackages = self::hasSelectedPackages($inputData);

        $steps = collect();

        $steps->push(new InstallStepData(self::STEP_PREFLIGHT_CHECKS, 'Run preflight checks'));

        if ($inputData->freshInstall) {
            $steps->push(new InstallStepData(self::STEP_PREPARE_FRESH_INSTALL, 'Refresh database'));
        }

        $steps->push(new InstallStepData(self::STEP_PREPARE_ENVIRONMENT, 'Prepare environment'));
        $steps->push(new InstallStepData(self::STEP_PUBLISH_VENDOR_MIGRATIONS, 'Publish vendor migrations'));
        $steps->push(new InstallStepData(self::STEP_PUBLISH_CAPELL_MIGRATIONS, 'Publish Capell migrations'));
        $steps->push(new InstallStepData(self::STEP_PUBLISH_PACKAGE_MIGRATIONS, 'Publish package migrations'));
        $steps->push(new InstallStepData(self::STEP_RUN_MIGRATIONS_PRE, 'Run database migrations'));
        $steps->push(new InstallStepData(self::STEP_PUBLISH_CAPELL_SETTINGS_MIGRATIONS, 'Publish Capell settings migrations'));
        $steps->push(new InstallStepData(self::STEP_RUN_MIGRATIONS_MID, 'Run Capell settings migrations'));
        $steps->push(new InstallStepData(self::STEP_RESOLVE_USER, 'Set up admin user'));

        if ($shouldInstallFilamentPanel) {
            $steps->push(new InstallStepData(self::STEP_INSTALL_FILAMENT_PANEL, 'Install Filament panel'));
        }

        if ($inputData->extraPackages !== []) {
            $steps->push(new InstallStepData(self::STEP_REQUIRE_EXTRA_PACKAGES, 'Require extra packages'));
        }

        if ($shouldInstallFilamentPanelAfterRequiringPackages) {
            $steps->push(new InstallStepData(self::STEP_INSTALL_FILAMENT_PANEL, 'Install Filament panel'));
        }

        if ($inputData->installDeveloperTooling) {
            $steps->push(new InstallStepData(self::STEP_INSTALL_DEVELOPER_TOOLING, 'Install AI / Agent Bridge developer tooling'));
        }

        if ($inputData->extraPackages !== [] || $inputData->installDeveloperTooling) {
            $steps->push(new InstallStepData(self::STEP_PUBLISH_EXTRA_VENDOR_MIGRATIONS, 'Publish extra vendor migrations'));
        }

        if ($inputData->extraPackages !== []) {
            $steps->push(new InstallStepData(self::STEP_INSTALL_PACKAGES, 'Install required packages'));
        }

        if ($hasSelectedPackages) {
            $selectedPackages = self::selectedPackages($inputData);

            $selectedPackages
                ->reject(fn (PackageData $package): bool => $package->isCore())
                ->each(function (PackageData $package) use ($steps): void {
                    $steps->push(new InstallStepData(
                        self::packageInstallStepKey($package->name),
                        'Install ' . $package->getLabel(),
                        requiresResolvedUser: true,
                    ));
                });

            if ($inputData->seedDefaultData) {
                $selectedPackages
                    ->filter(fn (PackageData $package): bool => self::packageHasSetupLifecycle($package))
                    ->reject(fn (PackageData $package): bool => self::setupCommandIsCoveredByDemoCommand($inputData, $package))
                    ->each(function (PackageData $package) use ($steps): void {
                        $steps->push(new InstallStepData(
                            self::packageSetupStepKey($package->name),
                            'Set up ' . $package->getLabel(),
                            requiresResolvedUser: true,
                        ));
                    });
            }

            if ($inputData->demoContent) {
                $selectedPackages
                    ->filter(fn (PackageData $package): bool => PackageDemoLifecycle::shouldRunDemo($inputData, $package))
                    ->each(function (PackageData $package) use ($steps): void {
                        $steps->push(new InstallStepData(
                            self::packageDemoStepKey($package->name),
                            'Demo content for ' . $package->getLabel(),
                            requiresResolvedUser: true,
                        ));
                    });
            }

            $selectedPackages
                ->filter(fn (PackageData $package): bool => $package->getAfterInstallCommand() !== null && $package->getAfterInstallCommand() !== '')
                ->each(function (PackageData $package) use ($steps): void {
                    $steps->push(new InstallStepData(
                        self::packageAfterInstallStepKey($package->name),
                        'Post-install ' . $package->getLabel(),
                        requiresResolvedUser: true,
                    ));
                });
        }

        if (self::shouldIntegrateAdminPanel($inputData)) {
            $steps->push(new InstallStepData(self::STEP_INTEGRATE_ADMIN_PANEL, 'Integrate Capell Admin with Filament panel'));
        }

        $steps->push(new InstallStepData(self::STEP_RUN_MIGRATIONS_POST, 'Finalize migrations'));

        $steps->push(new InstallStepData(self::STEP_CLEAR_CACHES, 'Clear caches'));

        if ($inputData->installWelcomeRoute) {
            $steps->push(new InstallStepData(self::STEP_INSTALL_WELCOME_ROUTE, 'Remove existing home route'));
        }

        if ($inputData->rebuildResources) {
            $steps->push(new InstallStepData(self::STEP_REBUILD_RESOURCES, 'Rebuild frontend resources'));
        }

        if ($inputData->generateSitemap) {
            $steps->push(new InstallStepData(self::STEP_GENERATE_SITEMAP, 'Generate sitemap'));
        }

        if ($inputData->seedDatabase) {
            $steps->push(new InstallStepData(self::STEP_SEED_DATABASE, 'Seed database'));
        }

        $steps->push(new InstallStepData(self::STEP_RUN_DOCTOR_SUMMARY, 'Run Capell health summary'));

        $steps->push(new InstallStepData(self::STEP_MARK_CORE_INSTALLED, 'Mark Capell core installed'));

        return $steps;
    }

    /**
     * @param  array<int, array{key: string, label: string}>  $plan
     */
    public static function findNextStep(array $plan, string $currentKey): ?string
    {
        $count = count($plan);
        foreach ($plan as $index => $step) {
            if ($step['key'] === $currentKey && $index + 1 < $count) {
                return $plan[$index + 1]['key'];
            }
        }

        return null;
    }

    /**
     * @param  array<int, array{key: string, label: string}>  $plan
     */
    public static function labelForStep(array $plan, string $key): string
    {
        foreach ($plan as $step) {
            if ($step['key'] === $key) {
                return $step['label'];
            }
        }

        return $key;
    }

    public static function packageInstallStepKey(string $packageName): string
    {
        return self::STEP_INSTALL_PACKAGE_PREFIX . $packageName;
    }

    public static function packageSetupStepKey(string $packageName): string
    {
        return self::STEP_SETUP_PACKAGE_PREFIX . $packageName;
    }

    public static function packageDemoStepKey(string $packageName): string
    {
        return self::STEP_DEMO_PACKAGE_PREFIX . $packageName;
    }

    public static function packageAfterInstallStepKey(string $packageName): string
    {
        return self::STEP_AFTER_INSTALL_PACKAGE_PREFIX . $packageName;
    }

    public static function isPackageInstallStep(string $stepKey): bool
    {
        return str_starts_with($stepKey, self::STEP_INSTALL_PACKAGE_PREFIX);
    }

    public static function isPackageSetupStep(string $stepKey): bool
    {
        return str_starts_with($stepKey, self::STEP_SETUP_PACKAGE_PREFIX);
    }

    public static function isPackageAfterInstallStep(string $stepKey): bool
    {
        return str_starts_with($stepKey, self::STEP_AFTER_INSTALL_PACKAGE_PREFIX);
    }

    public static function isPackageDemoStep(string $stepKey): bool
    {
        return str_starts_with($stepKey, self::STEP_DEMO_PACKAGE_PREFIX);
    }

    public static function packageNameFromStep(string $stepKey): string
    {
        foreach ([
            self::STEP_INSTALL_PACKAGE_PREFIX,
            self::STEP_SETUP_PACKAGE_PREFIX,
            self::STEP_DEMO_PACKAGE_PREFIX,
            self::STEP_AFTER_INSTALL_PACKAGE_PREFIX,
        ] as $prefix) {
            if (str_starts_with($stepKey, $prefix)) {
                return substr($stepKey, strlen($prefix));
            }
        }

        return '';
    }

    /**
     * @return Collection<string, PackageData>
     */
    private static function selectedPackages(InstallInputData $inputData): Collection
    {
        return resolve(PackageWorkflowPlanner::class)->expandAndOrder(
            CapellCore::getPackages(),
            array_values(array_unique([
                ...$inputData->packages,
                ...$inputData->extraPackages,
            ])),
            $inputData->freshInstall,
        );
    }

    private static function setupCommandIsCoveredByDemoCommand(InstallInputData $inputData, PackageData $package): bool
    {
        return $inputData->demoContent
            && $package->getSetupCommand() !== null
            && $package->getSetupCommand() !== ''
            && $package->getSetupCommand() === $package->getDemoCommand();
    }

    private static function packageHasSetupLifecycle(PackageData $package): bool
    {
        if ($package->getSetupCommand() !== null && $package->getSetupCommand() !== '') {
            return true;
        }

        return $package->getSetupAction() !== null && $package->getSetupAction() !== '';
    }

    /**
     * @param  array<int, string>  $packageNames
     */
    private static function shouldInstallFilamentPanel(array $packageNames): bool
    {
        return in_array('capell-app/admin', $packageNames, true);
    }

    private static function hasSelectedPackages(InstallInputData $inputData): bool
    {
        $packageNames = array_values(array_unique([
            ...$inputData->packages,
            ...$inputData->extraPackages,
        ]));

        return CapellCore::getPackages()
            ->filter(fn (PackageData $package): bool => in_array($package->name, $packageNames, true))
            ->isNotEmpty();
    }

    private static function shouldIntegrateAdminPanel(InstallInputData $inputData): bool
    {
        return $inputData->integrateAdminPanel
            && ! $inputData->seedDefaultData
            && in_array('capell-app/admin', [
                ...$inputData->packages,
                ...$inputData->extraPackages,
            ], true);
    }
}
