<?php

declare(strict_types=1);

use Capell\Admin\Enums\DashboardEnum;
use Capell\Admin\Facades\CapellAdmin;
use Capell\Admin\Filament\Pages\CapellDashboard;
use Capell\Admin\Support\Bridges\AdminBridgeRegistry;
use Capell\Installer\Bridges\InstallerAdminBridge;
use Capell\Installer\Filament\Pages\InstallCapellPage;
use Capell\Installer\Filament\Pages\InstallGuidePage;
use Capell\Installer\Filament\Pages\InstallProgressPage;
use Capell\Installer\Filament\Widgets\CapellNotInstalledFilamentWidget;
use Capell\Installer\Providers\InstallerAdminServiceProvider;
use Capell\Installer\Providers\InstallerServiceProvider;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;

it('registers the installer admin bridge through the admin-only provider', function (): void {
    $this->app->register(InstallerAdminServiceProvider::class);

    expect(resolve(AdminBridgeRegistry::class)->classes(InstallerServiceProvider::$packageName))
        ->toBe([InstallerAdminBridge::class])
        ->and(CapellAdmin::getAdminSurfaceRegistry()->pages())
        ->toContain(InstallCapellPage::class, InstallGuidePage::class, InstallProgressPage::class)
        ->and(CapellAdmin::getDashboardFilamentWidgets(DashboardEnum::NotInstalled))
        ->toContain(CapellNotInstalledFilamentWidget::class)
        ->and(FilamentView::hasRenderHook(
            PanelsRenderHook::PAGE_HEADER_WIDGETS_BEFORE,
            CapellDashboard::class,
        ))->toBeTrue();
});

it('keeps repeated admin provider registration idempotent', function (): void {
    $this->app->register(InstallerAdminServiceProvider::class, force: true);
    $this->app->register(InstallerAdminServiceProvider::class, force: true);

    expect(resolve(AdminBridgeRegistry::class)->classes(InstallerServiceProvider::$packageName))
        ->toBe([InstallerAdminBridge::class])
        ->and(CapellAdmin::getAdminSurfaceRegistry()->pages())
        ->toContain(InstallCapellPage::class, InstallGuidePage::class, InstallProgressPage::class)
        ->and(CapellAdmin::getDashboardFilamentWidgets(DashboardEnum::NotInstalled))
        ->toContain(CapellNotInstalledFilamentWidget::class);
});
