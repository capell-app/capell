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
 * Sent to the original submitter when their page has been rejected.
 * Includes any reviewer notes so they know what needs to be addressed.
 */
class PageRejectedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly Page $page,
        private readonly User $rejector,
        private readonly ?string $notes = null,
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

        $message = (new MailMessage)
            ->subject(__('capell-admin::notification.rejected_subject', [
                'page' => $pageTitle,
                'site' => $siteName,
            ]))
            ->greeting(__('capell-admin::notification.rejected_greeting', [
                'name' => $notifiable->name ?? $notifiable->email,
            ]))
            ->line(__('capell-admin::notification.rejected_intro', [
                'page' => $pageTitle,
                'site' => $siteName,
                'rejector' => $this->rejector->name ?? $this->rejector->email,
            ]));

        if (filled($this->notes)) {
            $message->line(__('capell-admin::notification.rejected_notes', ['notes' => $this->notes]));
        }

        return $message
            ->action(__('capell-admin::notification.rejected_cta'), $editUrl)
            ->line(__('capell-admin::notification.approval_footer'));
    }

    /** @return array<string, mixed> */
    public function toArray(User $notifiable): array
    {
        return [
            'page_id' => $this->page->getKey(),
            'page_name' => $this->page->name,
            'rejector' => $this->rejector->getKey(),
            'notes' => $this->notes,
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
