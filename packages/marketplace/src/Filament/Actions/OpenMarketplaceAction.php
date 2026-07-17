<?php

declare(strict_types=1);

namespace Capell\Marketplace\Filament\Actions;

use Capell\Admin\Filament\Pages\ExtensionsPage;
use Capell\Marketplace\Filament\Support\MarketplaceCatalogueRecordProvider;
use Filament\Actions\Action;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\View\View;

final class OpenMarketplaceAction
{
    public static function name(): string
    {
        return 'openMarketplace';
    }

    public static function make(MarketplaceConnectionFormModel $connection): Action
    {
        return Action::make(self::name())
            ->label((string) __('capell-marketplace::marketplace.marketplace.extensions_marketplace'))
            ->icon(Heroicon::OutlinedShoppingBag)
            ->color('primary')
            ->visible(fn (): bool => ExtensionsPage::canAccess() && $connection->connectionState() !== 'needs_configuration')
            ->authorize(fn (): bool => ExtensionsPage::canAccess() && $connection->connectionState() !== 'needs_configuration')
            ->modalHeading((string) __('capell-marketplace::marketplace.marketplace.extensions_marketplace'))
            ->modalDescription((string) __('capell-marketplace::marketplace.explorer.description'))
            ->slideOver()
            ->modalWidth(Width::ScreenExtraLarge)
            ->stickyModalHeader()
            ->stickyModalFooter()
            ->modalCloseButton()
            ->modalCancelAction(fn (Action $action): Action => $action
                ->label((string) __('capell-marketplace::marketplace.marketplace.close_marketplace'))
                ->color('gray'))
            ->modalSubmitAction(false)
            ->modalFooterActions([
                Action::make('marketplaceSelectionFooter')
                    ->view('capell-marketplace::filament.actions.open-marketplace-footer'),
            ])
            ->modalContent(function (mixed $livewire) use ($connection): View {
                resolve(MarketplaceCatalogueRecordProvider::class)->queueDefaultWarm();

                return view('capell-marketplace::filament.actions.open-marketplace', [
                    'initialSearch' => $livewire instanceof ExtensionsPage
                        ? $livewire->extensionTableSearchTerm()
                        : null,
                    'marketplaceConnection' => $connection,
                ]);
            });
    }
}
