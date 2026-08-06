<?php

declare(strict_types=1);

namespace Capell\Marketplace\Filament\Support;

use Capell\Marketplace\Filament\Pages\MarketplacePackageOperationsPage;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Log;
use Throwable;

final class MarketplaceErrorPresenter
{
    /** @param array<string, mixed> $context */
    public static function notification(string $title, Throwable $throwable, array $context = []): Notification
    {
        Log::warning('capell-marketplace: operator action failed', [
            ...$context,
            'exception_class' => $throwable::class,
        ]);

        return Notification::make()
            ->danger()
            ->title($title)
            ->body((string) __('capell-marketplace::marketplace.errors.operator_action_failed'))
            ->actions([
                Action::make('marketplaceErrorDetails')
                    ->label((string) __('capell-marketplace::marketplace.errors.view_details'))
                    ->icon(Heroicon::OutlinedQueueList)
                    ->url(MarketplacePackageOperationsPage::getUrl())
                    ->button(),
            ])
            ->persistent();
    }
}
