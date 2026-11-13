<?php

declare(strict_types=1);

namespace Capell\Admin\Notifications;

use Capell\Admin\Enums\RolePreset;
use Capell\Admin\Support\Users\InvitationPasswordBroker;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use SensitiveParameter;

final class UserInvitationNotification extends Notification
{
    use Queueable;

    public function __construct(
        #[SensitiveParameter]
        private readonly string $acceptUrl,
        private readonly string $inviterName,
        private readonly string $siteName,
        private readonly ?RolePreset $preset,
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
            ->subject((string) __('capell-admin::invitations.mail.subject', ['site' => $this->siteName]))
            ->greeting((string) __('capell-admin::invitations.mail.greeting'));

        if (! $this->preset instanceof RolePreset) {
            $message->line((string) __('capell-admin::invitations.mail.intro_without_role', [
                'inviter' => $this->inviterName,
                'site' => $this->siteName,
            ]));
        } else {
            $message
                ->line((string) __('capell-admin::invitations.mail.intro', [
                    'inviter' => $this->inviterName,
                    'site' => $this->siteName,
                    'role' => $this->preset->label(),
                ]))
                ->line((string) __('capell-admin::role_presets.can_heading'));

            foreach ($this->preset->canDo() as $line) {
                $message->line('- ' . $line);
            }
        }

        return $message
            ->action((string) __('capell-admin::invitations.mail.action'), $this->acceptUrl)
            ->line((string) __('capell-admin::invitations.mail.expiry', ['days' => InvitationPasswordBroker::expiresInDays()]))
            ->line((string) __('capell-admin::invitations.mail.ignore'));
    }
}
