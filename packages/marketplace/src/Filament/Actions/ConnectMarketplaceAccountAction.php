<?php

declare(strict_types=1);

namespace Capell\Marketplace\Filament\Actions;

use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Livewire\Component;

final class ConnectMarketplaceAccountAction
{
    public static function make(MarketplaceConnectionFormModel $connection): Action
    {
        return Action::make('connectMarketplaceAccount')
            ->label((string) __('capell-marketplace::marketplace.marketplace.connect_account_button'))
            ->icon(Heroicon::OutlinedUserCircle)
            ->color('primary')
            ->tooltip((string) __('capell-marketplace::marketplace.marketplace.connect_account_tooltip'))
            ->visible(fn (): bool => $connection->canManageConnectionActions()
                && $connection->connectionState() === 'not_connected')
            ->authorize(fn (): bool => $connection->canManageConnectionActions())
            ->disabled(fn (): bool => ! $connection->canStartRegistration())
            ->action(function (Component $livewire) use ($connection): void {
                abort_unless($connection->canManageConnectionActions(), 403);

                $approvalUrl = $connection->startAccountConnection();

                if ($approvalUrl === null) {
                    return;
                }

                $livewire->redirect($approvalUrl);
            });
    }
}
