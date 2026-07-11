<?php

declare(strict_types=1);

namespace Capell\Marketplace\Filament\Actions;

use Capell\Marketplace\Support\MarketplaceWebUrl;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;

final class CreateMarketplaceAccountAction
{
    public static function make(MarketplaceConnectionFormModel $connection): Action
    {
        return Action::make('createMarketplaceAccount')
            ->label((string) __('capell-marketplace::marketplace.marketplace.create_account_button'))
            ->icon(Heroicon::OutlinedUserPlus)
            ->color('gray')
            ->tooltip((string) __('capell-marketplace::marketplace.marketplace.create_account_tooltip'))
            ->visible(fn (): bool => $connection->canManageConnectionActions()
                && $connection->connectionState() === 'not_connected')
            ->authorize(fn (): bool => $connection->canManageConnectionActions())
            ->url(fn (): string => MarketplaceWebUrl::resolve() . '/register', shouldOpenInNewTab: true);
    }
}
