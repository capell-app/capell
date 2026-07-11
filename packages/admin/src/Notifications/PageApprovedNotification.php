<?php

declare(strict_types=1);

namespace Capell\Admin\Notifications;

use Capell\Core\Models\Page;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Auth\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Throwable;

/**
 * Sent to the original submitter when their page has been fully approved
 * (both levels) and is now ready to publish.
 */
class PageApprovedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly Page $page,
        private readonly User $approver,
    ) {}

    /** @return array<string> */
    public function via(User $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(User $notifiable): MailMessage
    {
        $siteName = $this->page->site->name ?? config('app.name');
        $pageTitle = $this->page->name;
        $editUrl = $this->resolveEditUrl();

        return (new MailMessage)
            ->subject(__('capell-admin::notification.approved_subject', [
                'page' => $pageTitle,
                'site' => $siteName,
            ]))
            ->greeting(__('capell-admin::notification.approved_greeting', [
                'name' => $notifiable->name ?? $notifiable->email,
            ]))
            ->line(__('capell-admin::notification.approved_intro', [
                'page' => $pageTitle,
                'site' => $siteName,
                'approver' => $this->approver->name ?? $this->approver->email,
            ]))
            ->action(__('capell-admin::notification.approved_cta'), $editUrl)
            ->line(__('capell-admin::notification.approval_footer'));
    }

    /** @return array<string, mixed> */
    public function toArray(User $notifiable): array
    {
        return [
            'page_id' => $this->page->getKey(),
            'page_name' => $this->page->name,
            'approver' => $this->approver->getKey(),
        ];
    }

    private function resolveEditUrl(): string
    {
        try {
            return route('filament.admin.resources.pages.edit', ['record' => $this->page->getKey()]);
        } catch (Throwable) {
            return config('app.url', '/');
        }
    }
}
