<?php

declare(strict_types=1);

namespace Capell\Marketplace\Filament\Extenders;

use Capell\Admin\Contracts\Extenders\ResourceHeaderActionExtender;
use Capell\Admin\Filament\Resources\Themes\Pages\ManageThemes;
use Filament\Actions\Action;
use Filament\Support\Enums\Width;

final class ThemeMarketplaceHeaderActionExtender implements ResourceHeaderActionExtender
{
    public function supports(string $pageClass): bool
    {
        return $pageClass === ManageThemes::class;
    }

    public function actions(): array
    {
        return [
            Action::make('installMarketplaceTheme')
                ->label(__('capell-marketplace::marketplace.themes.install_button'))
                ->icon('heroicon-o-shopping-bag')
                ->slideOver()
                ->modalWidth(Width::SevenExtraLarge)
                ->modalSubmitAction(false)
                ->modalCancelActionLabel(__('capell-admin::button.close'))
                ->modalFooterActions([
                    Action::make('marketplaceSelectionFooter')
                        ->view('capell-marketplace::filament.actions.open-marketplace-footer'),
                ])
                ->modalHeading(__('capell-marketplace::marketplace.themes.install_heading'))
                ->modalDescription(__('capell-marketplace::marketplace.themes.install_description'))
                ->modalContent(view('capell-marketplace::filament.actions.theme-marketplace-browser')),
        ];
    }
}
