<?php

declare(strict_types=1);

namespace Capell\Installer\Providers;

use Capell\Core\Enums\PackageTypeEnum;
use Capell\Core\Events\CapellInstallationCompleted;
use Capell\Core\Events\DatabaseSchemaChanged;
use Capell\Core\Events\PackageInstalled;
use Capell\Core\Events\PackageUninstalled;
use Capell\Core\Models\Site;
use Capell\Core\Support\Install\InstallPatchConfirmation;
use Capell\Core\Support\Install\InstallPatchContext;
use Capell\Core\Support\Install\InstallPatchRegistry;
use Capell\Core\Support\Packages\AbstractPackageServiceProvider;
use Capell\Core\Support\Patching\Patch;
use Capell\Installer\Support\InstallerDatabaseTableState;
use Capell\Installer\Support\InstallerInstallationState;
use Capell\Installer\Support\InstallerRuntimeMemo;
use Capell\Installer\Support\InstallGuide\Patches\AdminPanelColorsPatch;
use Capell\Installer\Support\InstallGuide\Patches\AdminPanelDashboardPatch;
use Capell\Installer\Support\InstallGuide\Patches\AdminPanelNavigationPatch;
use Capell\Installer\Support\InstallGuide\Patches\AdminPanelPluginPatch;
use Capell\Installer\Support\InstallGuide\Patches\AdminPanelThemePatch;
use Capell\Installer\Support\InstallGuide\Patches\AdminPanelWidgetsPatch;
use Capell\Installer\Support\InstallGuide\Patches\DocOnlyMediaLibraryPatch;
use Capell\Installer\Support\InstallGuide\Patches\DocOnlyQueueWorkerPatch;
use Capell\Installer\Support\InstallGuide\Patches\DocOnlyWebServerPatch;
use Capell\Installer\Support\InstallGuide\Patches\EnvQueueConnectionPatch;
use Capell\Installer\Support\InstallGuide\Patches\EnvSettingsCachePatch;
use Capell\Installer\Support\InstallGuide\Patches\FilesystemsPageCacheDiskPatch;
use Capell\Installer\Support\InstallGuide\Patches\LoggingCapellChannelPatch;
use Capell\Installer\Support\InstallGuide\Patches\RemoveWelcomeRoutePatch;
use Capell\Installer\Support\InstallGuide\Patches\ThemeSourcesPatch;
use Capell\Installer\Support\InstallGuide\Patches\UserModelPatch;
use Capell\Installer\Support\InstallGuide\Patches\ViteThemeInputPatch;
use Capell\Installer\Support\InstallGuide\PatchRegistry;
use Capell\Installer\Support\Preflight\InstallerPreflight;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Support\Facades\Event;
use Override;
use Spatie\LaravelPackageTools\Package;

class InstallerServiceProvider extends AbstractPackageServiceProvider
{
    public static string $name = 'capell-installer';

    public static string $packageName = 'capell-app/installer';

    public static PackageTypeEnum $type = PackageTypeEnum::Package;

    public function bootingPackage(): void
    {
        $this->loadRoutesFrom(__DIR__ . '/../../routes/web.php');
        $this->fallbackDatabaseDriversIfNeeded();

        // The installed-state probe answers "is there a site yet?", and its result
        // is cached without expiry. The events below cover installer-driven setup,
        // while this model event catches sites created by seeders or application code.
        Site::created(static function (): void {
            InstallerInstallationState::forget();
        });
    }

    public function configurePackage(Package $package): void
    {
        $package
            ->name(self::$name)
            ->hasViews('capell-installer')
            ->hasTranslations()
            ->hasConfigFile('capell-installer');
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(PatchRegistry::class, fn (): PatchRegistry => new PatchRegistry);
        $this->app->scoped(InstallerPreflight::class);
        $this->app->scoped(InstallerRuntimeMemo::class);

        Event::listen([
            CapellInstallationCompleted::class,
            DatabaseSchemaChanged::class,
            MigrationsEnded::class,
            PackageInstalled::class,
            PackageUninstalled::class,
        ], static function (): void {
            InstallerInstallationState::forget();
            InstallerDatabaseTableState::forget();
        });

    }

    /**
     * The installer is the pre-install runtime, so this work must remain
     * available before Capell can record the package as installed.
     */
    #[Override]
    protected function bootPackage(): self
    {
        $this->registerPatches();
        $this->registerInstallPatches();

        return $this;
    }

    /**
     * Switch SESSION_DRIVER and CACHE_STORE to "file" for this request when the
     * corresponding database tables do not exist yet (fresh-database scenario).
     * This lets the installer page load and start the installation even when the
     * application is configured to use database-backed sessions and cache.
     */
    private function fallbackDatabaseDriversIfNeeded(): void
    {
        if ($this->app->runningInConsole()) {
            return;
        }

        $databaseDrivers = array_filter([
            'session.driver' => config('session.driver') === 'database'
                ? (string) config('session.table', 'sessions')
                : null,
            'cache.default' => config('cache.default') === 'database'
                ? (string) config('cache.stores.database.table', 'cache')
                : null,
        ]);

        if ($databaseDrivers === []) {
            return;
        }

        $availableTables = InstallerDatabaseTableState::availableTables();

        foreach ($databaseDrivers as $configuredDriverKey => $table) {
            if (! in_array($table, $availableTables, true)) {
                config([$configuredDriverKey => 'file']);
            }
        }
    }

    private function registerPatches(): void
    {
        /** @var PatchRegistry $registry */
        $registry = $this->app->make(PatchRegistry::class);

        $registry->register(new UserModelPatch);
        $registry->register(new AdminPanelColorsPatch);
        $registry->register(new AdminPanelDashboardPatch);
        $registry->register(new AdminPanelNavigationPatch);
        $registry->register(new AdminPanelPluginPatch);
        $registry->register(new AdminPanelThemePatch);
        $registry->register(new AdminPanelWidgetsPatch);
        $registry->register(new ThemeSourcesPatch);
        $registry->register(new ViteThemeInputPatch);
        $registry->register(new RemoveWelcomeRoutePatch);
        $registry->register(new EnvQueueConnectionPatch);
        $registry->register(new EnvSettingsCachePatch);
        $registry->register(new FilesystemsPageCacheDiskPatch);
        $registry->register(new LoggingCapellChannelPatch);
        $registry->register(new DocOnlyQueueWorkerPatch);
        $registry->register(new DocOnlyWebServerPatch);
        $registry->register(new DocOnlyMediaLibraryPatch);
    }

    /**
     * Contribute install-time patches to Core's install patch registry so
     * `capell:install` can prepare the application without depending on
     * installer classes.
     */
    private function registerInstallPatches(): void
    {
        /** @var InstallPatchRegistry $installPatchRegistry */
        $installPatchRegistry = $this->app->make(InstallPatchRegistry::class);

        $installPatchRegistry->register(
            static fn (InstallPatchContext $context): ?Patch => $context->hasPackage('capell-app/admin')
                ? new UserModelPatch
                : null,
        );

        $installPatchRegistry->register(
            static fn (InstallPatchContext $context): ?Patch => $context->hasPackage('capell-app/admin') && $context->hasFilamentAdminPanelProvider
                ? new AdminPanelThemePatch
                : null,
            new InstallPatchConfirmation(
                label: __('capell-installer::install-guide.admin_panel_theme_confirm_label'),
                hint: __('capell-installer::install-guide.admin_panel_theme_confirm_hint'),
                skippedMessage: __('capell-installer::install-guide.admin_panel_theme_skipped'),
            ),
        );

        $installPatchRegistry->register(
            static fn (InstallPatchContext $context): ?Patch => $context->hasPackage('capell-app/admin')
                ? new ViteThemeInputPatch
                : null,
        );
    }
}
