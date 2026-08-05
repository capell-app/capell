<?php

declare(strict_types=1);

namespace Capell\Marketplace\Actions;

use Capell\Admin\Actions\Notifications\ResolveAdminNotificationRecipientsAction;
use Capell\Admin\Enums\AdminNotificationGroupEnum;
use Capell\Marketplace\Data\MarketplaceCommercialWarningData;
use Capell\Marketplace\Filament\Pages\MarketplacePurchasesPage;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use Throwable;

final class NotifyMarketplaceCommercialWarningsAction
{
    use AsFake;
    use AsObject;

    /** @param array<string, mixed> $commercial */
    public function handle(array $commercial): void
    {
        if (! Schema::hasTable('notifications')) {
            return;
        }

        $recipients = ResolveAdminNotificationRecipientsAction::run(AdminNotificationGroupEnum::PackageOperations)
            ->filter(fn (Model $recipient): bool => $recipient instanceof Authenticatable);

        foreach (BuildMarketplaceCommercialWarningsAction::run($commercial) as $warning) {
            foreach ($recipients as $recipient) {
                if (! $recipient instanceof Authenticatable) {
                    continue;
                }

                if (! $recipient instanceof Model) {
                    continue;
                }

                $dedupeKey = sprintf(
                    'capell-marketplace.commercial-warning.%s.%s.%s',
                    $warning->key,
                    $warning->status,
                    (string) $recipient->getKey(),
                );

                if (! Cache::add($dedupeKey, true, now()->addDay())) {
                    continue;
                }

                $this->send($recipient, $warning);
            }
        }
    }

    private function send(Authenticatable&Model $recipient, MarketplaceCommercialWarningData $warning): void
    {
        $notification = Notification::make('marketplace-commercial-' . $warning->key)
            ->title((string) __('capell-marketplace::marketplace.purchases.warning.' . $warning->status . '.title', [
                'name' => $warning->name,
            ]))
            ->body((string) __('capell-marketplace::marketplace.purchases.warning.' . $warning->status . '.body', [
                'name' => $warning->name,
                'date' => $warning->accessEndsAt?->translatedFormat('M j, Y'),
            ]))
            ->persistent()
            ->actions([
                Action::make('viewMarketplacePurchases')
                    ->label((string) __('capell-marketplace::marketplace.purchases.warning.view'))
                    ->icon(Heroicon::OutlinedCreditCard)
                    ->link()
                    ->url(MarketplacePurchasesPage::getUrl()),
            ]);

        $warning->severity === 'danger' ? $notification->danger() : $notification->warning();

        try {
            $notification->broadcast($recipient);
            $notification->sendToDatabase($recipient);
        } catch (Throwable $throwable) {
            report($throwable);
        }
    }
}
