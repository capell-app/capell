<?php

declare(strict_types=1);

namespace Capell\Admin\Notifications;

use Capell\Admin\Data\Upgrade\UpgradeSummaryData;
use Capell\Admin\Filament\Pages\UpgradePage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Throwable;

final class UpgradeSummaryNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly UpgradeSummaryData $summary,
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
        $message = (new MailMessage)
            ->subject((string) __('capell-admin::notification.upgrade_summary_subject'))
            ->greeting((string) __('capell-admin::notification.upgrade_summary_greeting'))
            ->line(trans_choice('capell-admin::notification.upgrade_summary_intro', $this->summary->maxVersionsBehind, [
                'count' => $this->summary->maxVersionsBehind,
            ]));

        if ($this->summary->securityCount > 0) {
            $message->line(trans_choice('capell-admin::notification.upgrade_summary_security', $this->summary->securityCount, [
                'count' => $this->summary->securityCount,
            ]));
        }

        if ($this->summary->updateCount > 0) {
            $message->line(trans_choice('capell-admin::notification.upgrade_summary_updates', $this->summary->updateCount, [
                'count' => $this->summary->updateCount,
            ]));
        }

        return $message
            ->action((string) __('capell-admin::notification.upgrade_summary_cta'), $this->upgradeUrl())
            ->line((string) __('capell-admin::notification.upgrade_summary_footer'));
    }

    private function upgradeUrl(): string
    {
        try {
            return UpgradePage::getUrl();
        } catch (Throwable) {
            return config('app.url', '/');
        }
    }
}
