<?php

declare(strict_types=1);

namespace Capell\Core\Actions\HostMonitor;

use Capell\Core\Data\Diagnostics\HostAssessmentData;
use Capell\Core\Enums\Diagnostics\HostMonitorStatus;
use Capell\Core\Notifications\HostMonitorAlertNotification;
use Capell\Core\Notifications\HostMonitorWebhookChannel;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Sends the messages the monitor raises: when a problem starts or worsens, as a
 * reminder while it lasts, and once when it clears. Off until switched on in
 * configuration. Each method returns the new "last notified" timestamp, which is
 * null once a problem has cleared.
 */
final readonly class NotifyHostMonitorAction
{
    public function handle(HostAssessmentData $assessment, HostMonitorStatus $previous, ?int $notifiedAt): ?int
    {
        if ($assessment->status === HostMonitorStatus::Ok) {
            if ($notifiedAt !== null && config('capell.health.host.notifications.notify_on_recovery', true) === true) {
                $this->send($assessment, HostMonitorAlertNotification::RECOVERED);
            }

            return null;
        }

        // A drop from critical to warning is progress, not news.
        if ($assessment->status->rank() < $previous->rank()) {
            return $notifiedAt;
        }

        if (! $assessment->status->isAtLeast($this->minimumStatus())) {
            return $notifiedAt;
        }

        return $this->send($assessment, HostMonitorAlertNotification::RAISED) ? now()->getTimestamp() : $notifiedAt;
    }

    public function remind(HostAssessmentData $assessment, ?int $notifiedAt): ?int
    {
        $minutes = (int) config('capell.health.host.notifications.reminder_minutes', 360);

        if ($minutes <= 0 || $notifiedAt === null || ! $assessment->status->isAtLeast($this->minimumStatus())) {
            return $notifiedAt;
        }

        if (now()->getTimestamp() - $notifiedAt < $minutes * 60) {
            return $notifiedAt;
        }

        return $this->send($assessment, HostMonitorAlertNotification::REMINDER) ? now()->getTimestamp() : $notifiedAt;
    }

    private function enabled(): bool
    {
        return config('capell.health.host.notifications.enabled', false) === true;
    }

    private function minimumStatus(): HostMonitorStatus
    {
        $minimum = HostMonitorStatus::fromConfig(config('capell.health.host.notifications.minimum_status'), HostMonitorStatus::Warning);

        return $minimum === HostMonitorStatus::Ok ? HostMonitorStatus::Warning : $minimum;
    }

    /** True when at least one channel accepted the message. */
    private function send(HostAssessmentData $assessment, string $event): bool
    {
        if (! $this->enabled()) {
            return false;
        }

        $channels = config('capell.health.host.notifications.channels', ['mail']);
        $channels = is_array($channels) ? array_values(array_filter($channels, is_string(...))) : [];

        $sent = false;

        foreach ($channels as $channel) {
            try {
                if ($channel === 'mail') {
                    $recipients = $this->recipients();

                    if ($recipients === []) {
                        continue;
                    }

                    Notification::route('mail', $recipients)->notify(new HostMonitorAlertNotification($assessment, $event, ['mail']));
                    $sent = true;
                } elseif ($channel === 'webhook') {
                    $url = config('capell.health.host.notifications.webhook_url');
                    if (! is_string($url)) {
                        continue;
                    }

                    if (preg_match('#\Ahttps?://#i', $url) !== 1) {
                        continue;
                    }

                    Notification::route(HostMonitorWebhookChannel::class, $url)->notify(new HostMonitorAlertNotification($assessment, $event, [HostMonitorWebhookChannel::class]));
                    $sent = true;
                }
            } catch (Throwable $throwable) {
                report($throwable);
            }
        }

        return $sent;
    }

    /** @return list<string> */
    private function recipients(): array
    {
        $recipients = config('capell.health.host.notifications.mail_to', []);

        return is_array($recipients) ? array_values(array_filter($recipients, static fn (mixed $address): bool => is_string($address) && filter_var($address, FILTER_VALIDATE_EMAIL) !== false)) : [];
    }
}
