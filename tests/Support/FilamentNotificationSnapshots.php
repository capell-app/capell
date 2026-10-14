<?php

declare(strict_types=1);

namespace Capell\Tests\Support;

use Filament\Notifications\Livewire\Notifications;
use Filament\Notifications\Notification;
use Filament\Notifications\NotificationMailbox;
use Illuminate\Support\Collection;

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

        return collect($component->notifications->all())
            ->values()
            ->map(static fn (Notification $notification): Notification => $notification);
    }
}
