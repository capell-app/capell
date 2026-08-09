<?php

declare(strict_types=1);

namespace Capell\Marketplace\Filament\Pages;

use BackedEnum;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Capell\Admin\Filament\Pages\ExtensionsPage;
use Capell\Marketplace\Actions\BuildMarketplacePurchasesPageDataAction;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Override;

final class MarketplacePurchasesPage extends Page
{
    use HasPageShield;

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
}
