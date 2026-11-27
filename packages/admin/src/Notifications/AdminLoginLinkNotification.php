<?php

declare(strict_types=1);

namespace Capell\Admin\Notifications;

use Capell\Admin\Support\Auth\AdminLoginLinks;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use SensitiveParameter;

final class AdminLoginLinkNotification extends Notification
{
    use Queueable;

    public function __construct(
        #[SensitiveParameter]
        private readonly string $url,
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
        $site = (string) config('app.name');

        return (new MailMessage)
            ->subject((string) __('capell-admin::login_links.mail.subject', ['site' => $site]))
            ->line((string) __('capell-admin::login_links.mail.intro', ['site' => $site]))
            ->action((string) __('capell-admin::login_links.mail.action'), $this->url)
            ->line((string) __('capell-admin::login_links.mail.expiry', ['minutes' => AdminLoginLinks::expiresInMinutes()]))
            ->line((string) __('capell-admin::login_links.mail.ignore'));
    }
}
