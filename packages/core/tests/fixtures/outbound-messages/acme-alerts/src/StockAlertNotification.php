<?php

declare(strict_types=1);

namespace Acme\OutboundAlerts;

use Illuminate\Notifications\Notification;

final class StockAlertNotification extends Notification
{
    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail', 'vonage'];
    }
}
