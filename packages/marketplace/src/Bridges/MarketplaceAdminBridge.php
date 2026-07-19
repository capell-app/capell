<?php

declare(strict_types=1);

namespace Capell\Marketplace\Bridges;

use Capell\Admin\Data\Bridges\AdminBridgeContextData;
use Capell\Admin\Enums\DashboardEnum;
use Capell\Admin\Filament\Pages\ExtensionsPage;
use Capell\Admin\Support\Bridges\AbstractAdminBridge;
use Capell\Admin\Support\Bridges\AdminBridgeRegistrar;
use Capell\Marketplace\Filament\Actions\ConnectMarketplaceAccountAction;
use Capell\Marketplace\Filament\Actions\CreateMarketplaceAccountAction;
use Capell\Marketplace\Filament\Actions\MarketplaceConnectionFormModel;
use Capell\Marketplace\Filament\Actions\MarketplaceInstallOperationsAction;
use Capell\Marketplace\Filament\Actions\OpenMarketplaceAction;
use Capell\Marketplace\Filament\Extenders\MarketplaceExtensionsPageExtender;
use Capell\Marketplace\Filament\Extenders\ThemeMarketplaceHeaderActionExtender;
use Capell\Marketplace\Filament\Pages\MarketplaceExtensionDetailPage;
use Capell\Marketplace\Filament\Pages\MarketplacePackageOperationsPage;
use Capell\Marketplace\Filament\Pages\MarketplacePage;
use Capell\Marketplace\Filament\Pages\ThemeExtensionPage;
use Capell\Marketplace\Filament\Support\MarketplaceCatalogueRecordProvider;
use Capell\Marketplace\Filament\Widgets\MarketplacePackageOperationsAlertFilamentWidget;
use Capell\Marketplace\Support\PendingMarketplaceThemeInstallProvider;
use Filament\Actions\Action;
use Override;

final class MarketplaceAdminBridge extends AbstractAdminBridge
{
    #[Override]
    public function isEnabled(AdminBridgeContextData $context): bool
    {
        return (bool) config('capell-marketplace.enabled', true);
    }

    public function register(AdminBridgeRegistrar $registrar, AdminBridgeContextData $context): void
    {
        $registrar->page(MarketplacePage::class);
        $registrar->page(MarketplaceExtensionDetailPage::class);
        $registrar->page(MarketplacePackageOperationsPage::class);
        $registrar->page(ThemeExtensionPage::class);
        $registrar->filamentDashboardWidget(
            MarketplacePackageOperationsAlertFilamentWidget::class,
            DashboardEnum::Main,
        );

        $registrar->extensionsPageExtender(MarketplaceExtensionsPageExtender::class);
        $registrar->extensionCatalogueMetadataProvider(MarketplaceCatalogueRecordProvider::class);
        $registrar->resourceHeaderActionExtender(ThemeMarketplaceHeaderActionExtender::class);
        $registrar->pendingThemeInstallProvider(PendingMarketplaceThemeInstallProvider::class);

        $registrar->extensionsPageHeaderAction(
            fn (): Action => OpenMarketplaceAction::make(resolve(MarketplaceConnectionFormModel::class)),
            'capell-marketplace.open-marketplace',
        );
        $registrar->extensionsPageHeaderAction(
            fn (): Action => MarketplaceInstallOperationsAction::make(),
            'capell-marketplace.install-operations',
        );
        $registrar->extensionsPageHeaderActionGroupAction(
            fn (): Action => ConnectMarketplaceAccountAction::make(resolve(MarketplaceConnectionFormModel::class)),
            'capell-marketplace.connect-account',
        );
        $registrar->extensionsPageHeaderActionGroupAction(
            fn (): Action => CreateMarketplaceAccountAction::make(resolve(MarketplaceConnectionFormModel::class)),
            'capell-marketplace.create-account',
        );
        $registrar->extensionsPageTableAction(
            fn (ExtensionsPage $page): Action => OpenMarketplaceAction::make(resolve(MarketplaceConnectionFormModel::class))
                ->label(function (mixed $livewire): string {
                    $search = $livewire instanceof ExtensionsPage ? $livewire->extensionTableSearchTerm() : null;

                    return filled($search)
                        ? (string) __('capell-marketplace::marketplace.marketplace.search_marketplace_for', ['search' => $search])
                        : (string) __('capell-marketplace::marketplace.marketplace.install_from_marketplace');
                })
                ->button(),
            'capell-marketplace.search-marketplace',
        );
    }
}
