<?php

declare(strict_types=1);

namespace Capell\Installer\Providers;

use Capell\Admin\Data\AdminSurfaceContributionData;
use Capell\Admin\Enums\DashboardEnum;
use Capell\Admin\Facades\CapellAdmin;
use Capell\Admin\Filament\Pages\CapellDashboard;
use Capell\Core\Enums\PackageTypeEnum;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Support\Install\InstallPatchConfirmation;
use Capell\Core\Support\Install\InstallPatchContext;
use Capell\Core\Support\Install\InstallPatchRegistry;
use Capell\Core\Support\Packages\AbstractPackageServiceProvider;
use Capell\Core\Support\Patching\Patch;
use Capell\Installer\Filament\Pages\InstallCapellPage;
use Capell\Installer\Filament\Pages\InstallGuidePage;
use Capell\Installer\Filament\Pages\InstallProgressPage;
use Capell\Installer\Filament\Widgets\CapellNotInstalledFilamentWidget;
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
use Filament\Pages\Page;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Schema;
use Override;
use Spatie\LaravelPackageTools\Package;
use Throwable;

class InstallerServiceProvider extends AbstractPackageServiceProvider
{
    public static string $name = 'capell-installer';

    public static string $packageName = 'capell-app/installer';

    public static PackageTypeEnum $type = PackageTypeEnum::Package;

    public function bootingPackage(): void
    {
        $this->loadRoutesFrom(__DIR__ . '/../../routes/web.php');
        $this->fallbackDatabaseDriversIfNeeded();
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
    }

    #[Override]
    public function registeringPackage(): void
    {
        // The installer is the pre-install runtime, so its booted lifecycle must
        // remain available before Capell can record this package as installed.

        $this->registerPackageMetadata();

        $this->booted(function (): void {
            $this->registerPatches();
            $this->registerInstallPatches();
            $this->registerFilamentIntegration();
        });
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

        $this->fallbackDatabaseBackedDriverIfTableIsMissing(
            configuredDriverKey: 'session.driver',
            configuredTableKey: 'session.table',
            defaultTable: 'sessions',
        );

        $this->fallbackDatabaseBackedDriverIfTableIsMissing(
            configuredDriverKey: 'cache.default',
            configuredTableKey: 'cache.stores.database.table',
            defaultTable: 'cache',
        );
    }

    private function fallbackDatabaseBackedDriverIfTableIsMissing(
        string $configuredDriverKey,
        string $configuredTableKey,
        string $defaultTable,
    ): void {
        if (config($configuredDriverKey) !== 'database') {
            return;
        }

        try {
            if (! Schema::hasTable(config($configuredTableKey, $defaultTable))) {
                config([$configuredDriverKey => 'file']);
            }
        } catch (Throwable) {
            config([$configuredDriverKey => 'file']);
        }
    }

    private function registerFilamentIntegration(): void
    {
        if (! class_exists(CapellAdmin::class)) {
            return;
        }

        if (! class_exists(Page::class)) {
            return;
        }

        CapellAdmin::contributeToAdminSurface(AdminSurfaceContributionData::page(InstallCapellPage::class));
        CapellAdmin::contributeToAdminSurface(AdminSurfaceContributionData::page(InstallGuidePage::class));
        CapellAdmin::contributeToAdminSurface(AdminSurfaceContributionData::page(InstallProgressPage::class));
        CapellAdmin::registerDashboardFilamentWidget(CapellNotInstalledFilamentWidget::class, DashboardEnum::NotInstalled);

        FilamentView::registerRenderHook(
            PanelsRenderHook::PAGE_HEADER_WIDGETS_BEFORE,
            fn (): View => view('capell-installer::components.installer-warning-hook'),
            CapellDashboard::class,
        );
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

    private function registerPackageMetadata(): void
    {
        CapellCore::registerPackage(
            static::$packageName,
            type: static::getType(),
            serviceProviderClass: static::class,
            path: realpath(__DIR__ . '/../..'),
            version: CapellCore::getInstalledPrettyVersion(static::$packageName),
        );
    }
}
