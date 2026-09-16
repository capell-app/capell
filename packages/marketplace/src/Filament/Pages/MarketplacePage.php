<?php

declare(strict_types=1);

namespace Capell\Marketplace\Filament\Pages;

use BackedEnum;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Capell\Admin\Filament\Pages\ExtensionsPage;
use Capell\Marketplace\Actions\UpdateMarketplaceSettingsAction;
use Capell\Marketplace\Enums\MarketplacePermission;
use Capell\Marketplace\Filament\Actions\ConnectMarketplaceAccountAction;
use Capell\Marketplace\Filament\Actions\MarketplaceConnectionFormModel;
use Capell\Marketplace\Filament\Actions\RunMarketplaceHeartbeatAction;
use Capell\Marketplace\Filament\Settings\MarketplaceSettingsSchema;
use Capell\Marketplace\Filament\Support\MarketplaceCatalogueRecordProvider;
use Capell\Marketplace\Settings\MarketplaceSettings;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
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

    protected static bool $shouldRegisterNavigation = true;

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
            ExtensionsPage::getUrl() => (string) __('capell-marketplace::marketplace.operations.extensions'),
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
            Action::make('ownerContactSettings')
                ->label(__('capell-marketplace::settings.title'))
                ->icon(Heroicon::OutlinedCog6Tooth)
                ->slideOver()
                ->modalWidth(Width::ScreenLarge)
                ->modalHeading(__('capell-marketplace::settings.title'))
                ->modalDescription(__('capell-marketplace::settings.owner_contact_description'))
                ->schema(fn (Schema $schema): array => MarketplaceSettingsSchema::make($schema))
                ->fillForm(fn (): array => resolve(MarketplaceSettings::class)->toArray())
                ->action(function (array $data): void {
                    UpdateMarketplaceSettingsAction::run($data);

                    Notification::make('marketplace-settings-saved')
                        ->title(__('capell-marketplace::settings.settings_saved'))
                        ->success()
                        ->send();
                }),
            Action::make('extensions')
                ->label((string) __('capell-marketplace::marketplace.operations.extensions'))
                ->icon(ExtensionsPage::getNavigationIcon())
                ->color('gray')
                ->url(ExtensionsPage::getUrl()),
            Action::make('packageOperations')
                ->label(MarketplacePackageOperationsPage::getNavigationLabel())
                ->icon(MarketplacePackageOperationsPage::getNavigationIcon())
                ->color('gray')
                ->url(MarketplacePackageOperationsPage::getUrl()),
            Action::make('purchases')
                ->label(MarketplacePurchasesPage::getNavigationLabel())
                ->icon(MarketplacePurchasesPage::getNavigationIcon())
                ->color('gray')
                ->url(MarketplacePurchasesPage::getUrl()),
            ConnectMarketplaceAccountAction::make($connection),
            RunMarketplaceHeartbeatAction::make($connection),
        ];
    }
}
