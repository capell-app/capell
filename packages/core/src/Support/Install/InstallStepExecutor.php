<?php

declare(strict_types=1);

namespace Capell\Core\Support\Install;

use Capell\Core\Actions\Install\ClearCachesAction;
use Capell\Core\Actions\Install\CreateAdditionalInstallUsersAction;
use Capell\Core\Actions\Install\GenerateSitemapAction;
use Capell\Core\Actions\Install\GrantInstallUserAdminAccessAction;
use Capell\Core\Actions\Install\InstallDeveloperToolingAction;
use Capell\Core\Actions\Install\InstallFilamentPanelAction;
use Capell\Core\Actions\Install\InstallPackagesAction;
use Capell\Core\Actions\Install\PrepareEnvironmentAction;
use Capell\Core\Actions\Install\PrepareFreshInstallAction;
use Capell\Core\Actions\Install\PublishCapellMigrationsAction;
use Capell\Core\Actions\Install\PublishPackageMigrationsAction;
use Capell\Core\Actions\Install\PublishVendorMigrationsAction;
use Capell\Core\Actions\Install\RequireExtraPackagesAction;
use Capell\Core\Actions\Install\ResolveInstallUserAction;
use Capell\Core\Actions\Install\RunInstallPreflightChecksAction;
use Capell\Core\Actions\Install\RunMigrationsAction;
use Capell\Core\Actions\RunNpmBuildAction;
use Capell\Core\Contracts\AdminPermissionSynchronizer;
use Capell\Core\Data\PackageData;
use Capell\Core\Enums\ExtensionStatusEnum;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\CapellExtension;
use Capell\Core\Support\Process\ArtisanSubprocessRunner;
use Filament\FilamentServiceProvider;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;
use Throwable;

final class InstallStepExecutor
{
    private const string FILAMENT_SERVICE_PROVIDER = FilamentServiceProvider::class;

    private const string INSTALL_PERMISSIONS_DOC_URL = 'https://docs.capell.app/getting-started/install/#install-time-write-permissions';

    public function execute(string $stepKey, InstallRunState $state): InstallRunState
    {
        if (InstallPlan::isPackageInstallStep($stepKey)) {
            $this->installPackage($state, InstallPlan::packageNameFromStep($stepKey));

            return $state;
        }

        if (InstallPlan::isPackageSetupStep($stepKey)) {
            $this->setupPackage($state, InstallPlan::packageNameFromStep($stepKey));

            return $state;
        }

        if (InstallPlan::isPackageDemoStep($stepKey)) {
            $this->demoPackage($state, InstallPlan::packageNameFromStep($stepKey));

            return $state;
        }

        if (InstallPlan::isPackageAfterInstallStep($stepKey)) {
            $this->afterInstallPackage($state, InstallPlan::packageNameFromStep($stepKey));

            return $state;
        }

        match ($stepKey) {
            InstallPlan::STEP_PREFLIGHT_CHECKS => RunInstallPreflightChecksAction::run($state->inputData, $state->reporter),
            InstallPlan::STEP_PREPARE_FRESH_INSTALL => PrepareFreshInstallAction::run($state->reporter),
            InstallPlan::STEP_PREPARE_ENVIRONMENT => PrepareEnvironmentAction::run($state->reporter),
            InstallPlan::STEP_PUBLISH_VENDOR_MIGRATIONS, InstallPlan::STEP_PUBLISH_EXTRA_VENDOR_MIGRATIONS => PublishVendorMigrationsAction::run($state->reporter),
            InstallPlan::STEP_PUBLISH_CAPELL_MIGRATIONS => PublishCapellMigrationsAction::run($state->reporter, publishSettings: false),
            InstallPlan::STEP_PUBLISH_PACKAGE_MIGRATIONS => $this->publishPackageMigrations($state),
            InstallPlan::STEP_RUN_MIGRATIONS_PRE => RunMigrationsAction::run($state->reporter, includeSettings: false),
            InstallPlan::STEP_PUBLISH_CAPELL_SETTINGS_MIGRATIONS => $this->publishSettingsMigrations($state),
            InstallPlan::STEP_RUN_MIGRATIONS_MID, InstallPlan::STEP_RUN_MIGRATIONS_POST => RunMigrationsAction::run($state->reporter),
            InstallPlan::STEP_RESOLVE_USER => $this->resolveInstallUser($state),
            InstallPlan::STEP_INSTALL_FILAMENT_PANEL => InstallFilamentPanelAction::run($state->reporter),
            InstallPlan::STEP_REQUIRE_EXTRA_PACKAGES => $this->requireExtraPackages($state),
            InstallPlan::STEP_INSTALL_DEVELOPER_TOOLING => InstallDeveloperToolingAction::run($state->reporter, $state->inputData->configureBoostDeveloperTooling),
            InstallPlan::STEP_INSTALL_PACKAGES => $this->installExtraPackages($state),
            InstallPlan::STEP_INTEGRATE_ADMIN_PANEL => $this->integrateAdminPanel($state),
            InstallPlan::STEP_CLEAR_CACHES => ClearCachesAction::run($state->inputData->cachesToClear, $state->reporter),
            InstallPlan::STEP_SEED_DATABASE => $this->seedDatabase($state),
            InstallPlan::STEP_REBUILD_RESOURCES => $this->rebuildResources($state),
            InstallPlan::STEP_INSTALL_WELCOME_ROUTE => $this->installWelcomeRoute($state),
            InstallPlan::STEP_GENERATE_SITEMAP => GenerateSitemapAction::run($state->reporter),
            InstallPlan::STEP_RUN_DOCTOR_SUMMARY => $this->runDoctorSummary($state),
            InstallPlan::STEP_MARK_CORE_INSTALLED => $this->markCoreInstalled($state),
            default => throw new RuntimeException(sprintf('Unknown install step: %s', $stepKey)),
        };

        return $state;
    }

    private function publishPackageMigrations(InstallRunState $state): void
    {
        PublishPackageMigrationsAction::run(
            packages: $this->packageMigrationTargets($state),
            reporter: $state->reporter,
            publishSettings: false,
        );
    }

    private function publishSettingsMigrations(InstallRunState $state): void
    {
        PublishCapellMigrationsAction::run($state->reporter, publishSchema: false);

        PublishPackageMigrationsAction::run(
            packages: $this->packageMigrationTargets($state),
            reporter: $state->reporter,
            publishSchema: false,
        );
    }

    private function requireExtraPackages(InstallRunState $state): void
    {
        RequireExtraPackagesAction::run($state->inputData->extraPackages, $state->reporter);

        $this->registerComposerDiscoveredProviders();
        CapellCore::clearExtensionCache();
        $state->refreshSelectedPackages();
    }

    /**
     * Composer's package discovery runs in the Composer child process. Register
     * newly discovered providers here so this installer process can continue.
     */
    private function registerComposerDiscoveredProviders(): void
    {
        $manifestPath = base_path('bootstrap/cache/packages.php');
        if (! is_file($manifestPath)) {
            return;
        }

        $manifest = require $manifestPath;
        if (! is_array($manifest)) {
            return;
        }

        collect($manifest)
            ->flatMap(fn (mixed $package): array => is_array($package) && is_array($package['providers'] ?? null)
                ? $package['providers']
                : [])
            ->filter(fn (mixed $providerClass): bool => is_string($providerClass) && class_exists($providerClass))
            ->unique()
            ->sortBy(fn (string $providerClass): int => $this->composerProviderPriority($providerClass))
            ->each(function (string $providerClass): void {
                app()->register($providerClass);
            });
    }

    private function composerProviderPriority(string $providerClass): int
    {
        if ($providerClass === self::FILAMENT_SERVICE_PROVIDER) {
            return 0;
        }

        if (str_starts_with($providerClass, 'Filament\\')) {
            return 10;
        }

        if (str_starts_with($providerClass, 'Capell\\')) {
            return 30;
        }

        return 20;
    }

    /**
     * @return Collection<string, PackageData>
     */
    private function packageMigrationTargets(InstallRunState $state): Collection
    {
        return CapellCore::getPackages(withoutCore: false)
            ->filter(fn (PackageData $package): bool => $package->isCore()
                && ! in_array($package->name, ['capell-app/capell', 'capell-app/core'], true))
            ->merge($state->selectedPackages())
            ->reject(fn (PackageData $package): bool => in_array($package->name, ['capell-app/capell', 'capell-app/core'], true))
            ->reject(fn (PackageData $package): bool => $package->getInstallCommand() !== null && $package->getInstallCommand() !== '')
            ->unique(fn (PackageData $package): string => $package->name)
            ->sortBy(fn (PackageData $package): int => $package->getSort())
            ->keyBy(fn (PackageData $package): string => $package->name);
    }

    private function resolveInstallUser(InstallRunState $state): void
    {
        $user = ResolveInstallUserAction::run(
            userId: $state->inputData->userId,
            newUser: $state->inputData->newUser,
            reporter: $state->reporter,
        );

        GrantInstallUserAdminAccessAction::run($user, $state->reporter);
        CreateAdditionalInstallUsersAction::run($state->inputData->additionalUsers, $state->reporter);

        $state->setResolvedUser($user);
    }

    private function installExtraPackages(InstallRunState $state): void
    {
        if ($state->inputData->extraPackages === []) {
            return;
        }

        CapellCore::clearExtensionCache();
        $state->refreshSelectedPackages();

        foreach ($state->inputData->extraPackages as $packageName) {
            $this->installPackage($state, $packageName);
        }

        if ($state->inputData->seedDefaultData) {
            foreach ($state->inputData->extraPackages as $packageName) {
                $this->setupPackage($state, $packageName);
            }
        }

        if ($state->inputData->demoContent) {
            foreach ($state->inputData->extraPackages as $packageName) {
                $this->demoPackage($state, $packageName);
            }
        }

        foreach ($state->inputData->extraPackages as $packageName) {
            $this->afterInstallPackage($state, $packageName);
        }
    }

    private function installPackage(InstallRunState $state, string $packageName): void
    {
        resolve(InstallPackagesAction::class)->installPackage(
            $state->inputData,
            $state->resolvedUser(),
            $packageName,
            $state->reporter,
        );
    }

    private function setupPackage(InstallRunState $state, string $packageName): void
    {
        resolve(InstallPackagesAction::class)->setupPackage(
            $state->inputData,
            $state->resolvedUser(),
            $packageName,
            $state->reporter,
        );
    }

    private function afterInstallPackage(InstallRunState $state, string $packageName): void
    {
        resolve(InstallPackagesAction::class)->afterInstallPackage(
            $state->inputData,
            $state->resolvedUser(),
            $packageName,
            $state->reporter,
        );
    }

    private function demoPackage(InstallRunState $state, string $packageName): void
    {
        resolve(InstallPackagesAction::class)->demoPackage(
            $state->inputData,
            $state->resolvedUser(),
            $packageName,
            $state->reporter,
        );
    }

    private function integrateAdminPanel(InstallRunState $state): void
    {
        if (! $this->shouldRunAdminPanelIntegration($state)) {
            return;
        }

        $state->reporter->step('Integrating Capell Admin with Filament panel…');

        $exitCode = Artisan::call('capell:admin-setup', [
            '--integration-only' => true,
            '--panel' => $state->inputData->adminPanel,
            '--schemas' => $state->inputData->adminDiscoverSchemas === []
                ? 'auto'
                : $this->formatAdminDiscoverSchemas($state->inputData->adminDiscoverSchemas),
            '--no-colors' => ! $state->inputData->adminAddColors,
            '--no-widgets' => ! $state->inputData->adminAddWidgets,
            '--no-navigation' => ! $state->inputData->adminAddNavigation,
            '--force' => true,
        ]);

        $output = trim(Artisan::output());
        if ($output !== '') {
            $state->reporter->report($output);
        }

        if ($exitCode !== 0) {
            throw new RuntimeException(sprintf("Command 'capell:admin-setup' failed with exit code %d.", $exitCode));
        }
    }

    private function seedDatabase(InstallRunState $state): void
    {
        $state->reporter->step('Seeding database…');

        $exitCode = Artisan::call('db:seed', [
            '--force' => true,
        ]);

        $output = trim(Artisan::output());
        if ($output !== '') {
            $state->reporter->report($output);
        }

        if ($exitCode !== 0) {
            throw new RuntimeException(sprintf("Command 'db:seed' failed with exit code %d.", $exitCode));
        }

        $state->reporter->report('✓ Database seeded');
    }

    private function rebuildResources(InstallRunState $state): void
    {
        $state->reporter->step('Rebuilding frontend resources…');

        try {
            RunNpmBuildAction::run();
            $state->reporter->report('✓ Frontend resources rebuilt');
        } catch (RuntimeException $runtimeException) {
            $state->reporter->error('⚠ Frontend resources were not rebuilt.');
            $state->reporter->error('The installer tried to run npm but the build failed. Log in to the server and run npm install, then npm run build.');
            $state->reporter->error($runtimeException->getMessage());

            throw $runtimeException;
        }
    }

    private function installWelcomeRoute(InstallRunState $state): void
    {
        $state->reporter->step('Removing existing home route…');

        try {
            $installed = resolve(WelcomeRouteInstaller::class)->install();
        } catch (Throwable $throwable) {
            $state->reporter->error('⚠ Existing home route was not removed automatically.');
            $state->reporter->error('Manual changes are required for .env or routes/web.php after install.');
            $state->reporter->error($throwable->getMessage());
            $state->reporter->error('Review the install-time write permissions and manual patch list: ' . self::INSTALL_PERMISSIONS_DOC_URL);

            return;
        }

        $state->reporter->report($installed
            ? '✓ Existing home route removed'
            : '✓ No removable home route found; skipped');
    }

    private function runDoctorSummary(InstallRunState $state): void
    {
        $this->syncAdminPermissions($state);

        $state->reporter->step('Running Capell health summary…');

        $exitCode = $this->runDoctorSummaryCommand($state);

        if ($exitCode !== 0) {
            $state->reporter->error('⚠ Capell health summary found issues.');
            $state->reporter->error('Installation stopped because the required health checks did not pass.');

            throw new RuntimeException('Capell health summary failed.');
        }
    }

    private function runDoctorSummaryCommand(InstallRunState $state): int
    {
        return $this->runDoctorSummaryInFreshProcess($state);
    }

    private function runDoctorSummaryInFreshProcess(InstallRunState $state): int
    {
        return resolve(ArtisanSubprocessRunner::class)->run(
            [
                'capell:doctor',
                '--install-summary',
                '--skip-package-doctors',
                '--no-interaction',
            ],
            function (string $line) use ($state): void {
                $state->reporter->report($line);
            },
        );
    }

    private function syncAdminPermissions(InstallRunState $state): void
    {
        if (! $this->shouldSyncAdminPermissions($state)) {
            return;
        }

        $state->reporter->step('Syncing admin permissions…');

        // On a fresh install the Filament AdminPanelProvider is created and
        // integrated on disk during this same process (filament:install +
        // capell:admin-setup), so it was never booted here and Filament has no
        // default panel. Permission sync reads Filament::getResources(), which
        // throws NoDefaultPanelSetException in that state. Run it in a fresh
        // process so it boots the freshly integrated provider and sees the
        // default panel and all registered Capell resources. When a default
        // panel is already booted (e.g. re-running on an installed app), sync
        // in-process to avoid spawning a subprocess.
        $synchronizer = app()->bound(AdminPermissionSynchronizer::class)
            ? resolve(AdminPermissionSynchronizer::class)
            : null;

        if (($synchronizer === null || ! $synchronizer->hasBootedPanel()) && $this->runAdminPermissionSyncInFreshProcess($state)) {
            $state->reporter->report('✓ Admin permissions synced');

            return;
        }

        if ($synchronizer === null) {
            return;
        }

        $synchronizer->syncForInstall();
        $state->reporter->report('✓ Admin permissions synced');
    }

    private function runAdminPermissionSyncInFreshProcess(InstallRunState $state): bool
    {
        $exitCode = resolve(ArtisanSubprocessRunner::class)->run(
            [
                'capell:admin-sync-permissions',
                '--mode=install',
                '--no-interaction',
            ],
            function (string $line) use ($state): void {
                $state->reporter->report($line);
            },
            timeout: null,
        );

        throw_if($exitCode !== 0, RuntimeException::class, 'Capell admin permission sync failed in a fresh process during install.');

        return true;
    }

    private function markCoreInstalled(InstallRunState $state): void
    {
        CapellExtension::query()->updateOrCreate(
            ['composer_name' => 'capell-app/core'],
            [
                'name' => 'Capell Core',
                'status' => ExtensionStatusEnum::Enabled,
                'installed_at' => now(),
                'is_paid_marketplace_extension' => false,
            ],
        );

        $state->reporter->report('✓ Installation complete!');
    }

    /**
     * @param  array<int, array{in: string, for: string}>  $schemas
     */
    private function formatAdminDiscoverSchemas(array $schemas): string
    {
        return collect($schemas)
            ->map(fn (array $schema): string => $schema['in'] . '=' . $schema['for'])
            ->implode(',');
    }

    /**
     * @param  Collection<string, PackageData>|null  $selectedPackages
     */
    private function shouldRunAdminPanelIntegration(InstallRunState $state, ?Collection $selectedPackages = null): bool
    {
        if (! $state->inputData->integrateAdminPanel || $state->inputData->seedDefaultData) {
            return false;
        }

        if (in_array('capell-app/admin', $state->inputData->packages, true)) {
            return true;
        }

        return ($selectedPackages ?? $state->selectedPackages())->contains(
            fn (PackageData $package): bool => $package->name === 'capell-app/admin',
        );
    }

    private function shouldSyncAdminPermissions(InstallRunState $state): bool
    {
        if (in_array('capell-app/admin', $state->inputData->packages, true)) {
            return true;
        }

        return $state->selectedPackages()->contains(
            fn (PackageData $package): bool => $package->name === 'capell-app/admin',
        );
    }
}
