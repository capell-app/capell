<?php

declare(strict_types=1);

namespace Capell\Marketplace\Filament\Pages;

use BackedEnum;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Capell\Admin\Filament\Pages\ExtensionsPage;
use Capell\Marketplace\Actions\BuildMarketplacePurchasesPageDataAction;
use Capell\Marketplace\Actions\RunMarketplaceHeartbeatAction;
use Capell\Marketplace\Filament\Actions\ConnectMarketplaceAccountAction;
use Capell\Marketplace\Filament\Actions\MarketplaceConnectionFormModel;
use Capell\Marketplace\Support\MarketplaceInstanceResolver;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Override;

final class MarketplacePurchasesPage extends Page
{
    use HasPageShield;

    public bool $refreshFailed = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCreditCard;

    protected static string|BackedEnum|null $activeNavigationIcon = Heroicon::CreditCard;

    protected static ?string $slug = 'extensions/marketplace/purchases';

    protected static bool $shouldRegisterNavigation = true;

    protected string $view = 'capell-marketplace::filament.pages.marketplace-purchases';

    #[Override]
    public static function canAccess(): bool
    {
        return MarketplacePage::canAccess();
    }

    #[Override]
    public static function getNavigationLabel(): string
    {
        return (string) __('capell-marketplace::marketplace.purchases.page_title');
    }

    #[Override]
    public static function getNavigationGroup(): string
    {
        return (string) __('capell-admin::navigation.group_system');
    }

    #[Override]
    public function getTitle(): string
    {
        return self::getNavigationLabel();
    }

    #[Override]
    public function getHeading(): string|Htmlable|null
    {
        return null;
    }

    /** @return array<string, mixed> */
    public function purchasesData(): array
    {
        return BuildMarketplacePurchasesPageDataAction::run();
    }

    #[Override]
    public function getBreadcrumbs(): array
    {
        return [
            ExtensionsPage::getUrl() => (string) __('capell-marketplace::marketplace.operations.extensions'),
            MarketplacePage::getUrl() => MarketplacePage::getNavigationLabel(),
            self::getNavigationLabel(),
        ];
    }

    /** @return list<Action> */
    #[Override]
    protected function getHeaderActions(): array
    {
        return [
            ConnectMarketplaceAccountAction::make(resolve(MarketplaceConnectionFormModel::class)),
            Action::make('refreshPurchases')
                ->label(__('capell-marketplace::marketplace.install.run_heartbeat'))
                ->visible(fn (): bool => ExtensionsPage::canManageExtensions())
                ->authorize(fn (): bool => ExtensionsPage::canManageExtensions())
                ->disabled(fn (): bool => resolve(MarketplaceInstanceResolver::class)->latest() === null)
                ->action(function (): void {
                    abort_unless(ExtensionsPage::canManageExtensions(), 403);
                    $result = RunMarketplaceHeartbeatAction::run();
                    $this->refreshFailed = ! $result->successful;
                    resolve(MarketplaceInstanceResolver::class)->forget();
                    Notification::make()->title($result->successful
                        ? __('capell-marketplace::marketplace.install.heartbeat_completed')
                        : __('capell-marketplace::marketplace.install.heartbeat_failed'))
                        ->status($result->successful ? 'success' : 'warning')->send();
                }),
        ];
    }
}
