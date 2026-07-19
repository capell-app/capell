<?php

declare(strict_types=1);

namespace Capell\Installer\Bridges;

use Capell\Admin\Data\Bridges\AdminBridgeContextData;
use Capell\Admin\Enums\DashboardEnum;
use Capell\Admin\Filament\Pages\CapellDashboard;
use Capell\Admin\Support\Bridges\AbstractAdminBridge;
use Capell\Admin\Support\Bridges\AdminBridgeRegistrar;
use Capell\Installer\Filament\Pages\InstallCapellPage;
use Capell\Installer\Filament\Pages\InstallGuidePage;
use Capell\Installer\Filament\Pages\InstallProgressPage;
use Capell\Installer\Filament\Widgets\CapellNotInstalledFilamentWidget;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Illuminate\Contracts\View\View;

final class InstallerAdminBridge extends AbstractAdminBridge
{
    public function register(AdminBridgeRegistrar $registrar, AdminBridgeContextData $context): void
    {
        $registrar->page(InstallCapellPage::class);
        $registrar->page(InstallGuidePage::class);
        $registrar->page(InstallProgressPage::class);
        $registrar->filamentDashboardWidget(
            CapellNotInstalledFilamentWidget::class,
            DashboardEnum::NotInstalled,
        );

        FilamentView::registerRenderHook(
            PanelsRenderHook::PAGE_HEADER_WIDGETS_BEFORE,
            fn (): View => view('capell-installer::components.installer-warning-hook'),
            CapellDashboard::class,
        );
    }
}
