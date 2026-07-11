<?php

declare(strict_types=1);

namespace Capell\Marketplace\Notifications;

use Capell\Marketplace\Filament\Pages\MarketplacePackageOperationsPage;
use Capell\Marketplace\Models\MarketplaceInstallAttempt;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class MarketplaceInstallOperationFailedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly MarketplaceInstallAttempt $attempt,
        private readonly string $reason,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject((string) __('capell-marketplace::marketplace.operations.notification_subject', [
                'package' => $this->attempt->composer_name,
            ]))
            ->line((string) __('capell-marketplace::marketplace.operations.notification_intro', [
                'package' => $this->attempt->composer_name,
                'status' => $this->attempt->status->value,
            ]))
            ->line($this->reason)
            ->action(
                (string) __('capell-marketplace::marketplace.install.check_operation'),
                MarketplacePackageOperationsPage::getUrl([
                    'operation' => $this->attempt->getKey(),
                    'tab' => 'failed',
                ]),
            )
            ->line((string) __('capell-marketplace::marketplace.operations.notification_footer'));
    }
}
