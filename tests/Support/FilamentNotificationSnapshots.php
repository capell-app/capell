<?php

declare(strict_types=1);

namespace Capell\Tests\Support;

use Filament\Notifications\Livewire\Notifications;
use Filament\Notifications\Notification;
use Filament\Notifications\NotificationMailbox;
use Illuminate\Support\Collection;
use UnexpectedValueException;

final class FilamentNotificationSnapshots
{
    /** @return Collection<int, Notification> */
    public static function pull(): Collection
    {
        // Follow Filament's assertion lifecycle, including deferred delivery when available.
        if (class_exists(NotificationMailbox::class)) {
            resolve(NotificationMailbox::class)->flush();
        }

        $component = new Notifications;
        $component->mount();

        if (class_exists(NotificationMailbox::class)) {
            $component->pullNotificationsFromSession();
        }

        $notifications = [];

        foreach ($component->notifications as $notification) {
            throw_unless($notification instanceof Notification, UnexpectedValueException::class, 'Delivered notifications must be Filament notification objects.');

            $notifications[] = $notification;
        }

        return new Collection($notifications);
    }
}
