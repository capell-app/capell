<?php

declare(strict_types=1);

namespace Capell\Core\Notifications;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Posts the alert as JSON with a "text" field, which Slack and Mattermost incoming webhooks accept. */
final class HostMonitorWebhookChannel
{
    public function send(mixed $notifiable, Notification $notification): void
    {
        if (! $notification instanceof HostMonitorAlertNotification || ! is_object($notifiable) || ! method_exists($notifiable, 'routeNotificationFor')) {
            return;
        }

        $url = $notifiable->routeNotificationFor(self::class, $notification);

        if (! is_string($url) || $url === '') {
            return;
        }

        try {
            $response = Http::timeout(5)->post($url, $notification->toWebhook($notifiable));
        } catch (ConnectionException) {
            // The address is a secret for most webhook providers, so keep it out of logs and exception messages.
            throw new RuntimeException('The host monitor webhook could not be reached.');
        }

        if ($response->failed()) {
            throw new RuntimeException('The host monitor webhook answered with status ' . $response->status() . '.');
        }
    }
}
