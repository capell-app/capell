<?php

declare(strict_types=1);

namespace Capell\Marketplace\Filament\Pages;

use BackedEnum;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Capell\Admin\Filament\Pages\ExtensionsPage;
use Capell\Marketplace\Enums\MarketplacePermission;
use Capell\Marketplace\Filament\Actions\ConnectMarketplaceAccountAction;
use Capell\Marketplace\Filament\Actions\MarketplaceConnectionFormModel;
use Capell\Marketplace\Filament\Actions\RunMarketplaceHeartbeatAction;
use Capell\Marketplace\Filament\Support\MarketplaceCatalogueRecordProvider;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Override;

final class MarketplacePage extends Page implements HasActions
{
    use HasPageShield;
    use InteractsWithActions;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingBag;

    protected static string|BackedEnum|null $activeNavigationIcon = Heroicon::ShoppingBag;

    protected static ?string $slug = 'extensions/marketplace';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'capell-marketplace::filament.pages.marketplace';

    #[Override]
    public static function canAccess(): bool
    {
        if (ExtensionsPage::canAccess()) {
            return true;
        }

        return auth()->user()?->can(MarketplacePermission::ViewMarketplacePage->value) ?? false;
    }

    #[Override]
    public static function getNavigationLabel(): string
    {
        return (string) __('capell-marketplace::navigation.extensions_marketplace');
    }

    #[Override]
    public static function getNavigationGroup(): string
    {
        return (string) __('capell-admin::navigation.group_system');
    }

    #[Override]
    public function getTitle(): string
    {
        return __('capell-marketplace::navigation.extensions_marketplace');
    }

    #[Override]
    public function getHeading(): string|Htmlable|null
    {
        return null;
    }

    public function marketplaceConnection(): MarketplaceConnectionFormModel
    {
        return resolve(MarketplaceConnectionFormModel::class);
    }

    public function mount(): void
    {
        resolve(MarketplaceCatalogueRecordProvider::class)->queueDefaultWarm(includeLocalExtensionState: ExtensionsPage::canAccess());
    }

    #[Override]
    public function getBreadcrumbs(): array
    {
        return [
            ExtensionsPage::getUrl() => ExtensionsPage::getNavigationLabel(),
            self::getNavigationLabel(),
        ];
    }

    /**
     * @return array<int, Action>
     */
    #[Override]
    protected function getHeaderActions(): array
    {
        $connection = $this->marketplaceConnection();

        return [
            Action::make('extensions')
                ->label(ExtensionsPage::getNavigationLabel())
                ->icon(ExtensionsPage::getNavigationIcon())
                ->color('gray')
                ->url(ExtensionsPage::getUrl()),
            Action::make('packageOperations')
                ->label(MarketplacePackageOperationsPage::getNavigationLabel())
                ->icon(MarketplacePackageOperationsPage::getNavigationIcon())
                ->color('gray')
                ->url(MarketplacePackageOperationsPage::getUrl()),
            ConnectMarketplaceAccountAction::make($connection),
            RunMarketplaceHeartbeatAction::make($connection),
        ];
    }
}
